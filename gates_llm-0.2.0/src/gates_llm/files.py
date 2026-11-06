"""Local, bounded preparation of documents for the two gateway APIs."""

import base64
import copy
import csv
import importlib
import io
import json
import warnings
import zipfile
from collections.abc import Sequence
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Literal

from .exceptions import FileInputError

DEFAULT_MAX_SOURCE_BYTES = 20_000_000
MAX_PDF_PAGES = 20
MAX_IMAGE_PIXELS = 25_000_000
MAX_IMAGE_DIMENSION = 2_048
_TEXT_EXTENSIONS = {".txt", ".md", ".markdown", ".csv", ".json"}
_IMAGE_EXTENSIONS = {".png", ".jpg", ".jpeg", ".webp", ".gif"}
_EXTENSIONS = _TEXT_EXTENSIONS | _IMAGE_EXTENSIONS | {".pdf", ".docx", ".xlsx"}


def _dependency(name: str) -> Any:
    try:
        return importlib.import_module(name)
    except ImportError as exc:
        raise FileInputError(
            f"{name} is required for this file. Install the downloaded SDK wheel "
            "with its files extra: pip install './gates_llm-0.2.0-py3-none-any.whl[files]'."
        ) from exc


def _text_part(filename: str, text: str) -> dict[str, Any]:
    if not text.strip():
        raise FileInputError(f"{filename} contains no readable text.")
    return {"type": "input_text", "text": f"Document: {filename}\n\n{text}"}


def _check_prepared_size(parts: list[dict[str, Any]], limit: int) -> None:
    if len(json.dumps(parts, ensure_ascii=False).encode("utf-8")) > limit:
        raise FileInputError(
            "Prepared content exceeds the preparation limit. Select fewer pages, "
            "sheets, or cells, or split the source explicitly."
        )


def _image_part(image: Any, image_module: Any) -> dict[str, Any]:
    width, height = image.size
    if width <= 0 or height <= 0 or width * height > MAX_IMAGE_PIXELS:
        raise FileInputError("An image must contain between 1 and 25,000,000 pixels.")
    image.load()
    oriented = _dependency("PIL.ImageOps").exif_transpose(image)
    try:
        with oriented.convert("RGBA") as rgba:
            prepared = image_module.new("RGB", rgba.size, "white")
            with rgba.getchannel("A") as alpha:
                prepared.paste(rgba, mask=alpha)
    finally:
        oriented.close()
    try:
        prepared.thumbnail(
            (MAX_IMAGE_DIMENSION, MAX_IMAGE_DIMENSION),
            image_module.Resampling.LANCZOS,
        )
        buffer = io.BytesIO()
        prepared.save(buffer, format="PNG")
        encoded = base64.b64encode(buffer.getvalue()).decode("ascii")
    finally:
        prepared.close()
    return {
        "type": "input_image",
        "image_url": f"data:image/png;base64,{encoded}",
        "detail": "high",
    }


def _office_archive(data: bytes, max_bytes: int) -> None:
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        entries = archive.infolist()
        if len(entries) > 10_000 or sum(item.file_size for item in entries) > max_bytes:
            raise FileInputError(
                "Expanded Office document exceeds the preparation limit. "
                "Select a smaller source document."
            )
        if any(item.flag_bits & 1 for item in entries):
            raise FileInputError("Encrypted Office documents are not supported.")


def _pdf_parts(
    data: bytes,
    filename: str,
    pages: Sequence[int] | None,
    mode: Literal["text_and_images", "text"],
    limit: int,
) -> list[dict[str, Any]]:
    pdfium = _dependency("pypdfium2")
    image_module = _dependency("PIL.Image") if mode == "text_and_images" else None
    parts: list[dict[str, Any]] = []
    with pdfium.PdfDocument(data) as document:
        if pdfium.raw.FPDF_GetSecurityHandlerRevision(document) >= 0:
            raise FileInputError("Encrypted PDFs are not supported. Use a decrypted copy.")
        selected = list(pages) if pages is not None else list(range(1, len(document) + 1))
        if not selected or len(selected) > MAX_PDF_PAGES:
            raise FileInputError("Select between 1 and 20 PDF pages using pages=[1, 2, ...].")
        if len(set(selected)) != len(selected) or any(
            type(page) is not int or page < 1 or page > len(document) for page in selected
        ):
            raise FileInputError("PDF pages must be unique, valid one-based page numbers.")
        for number in selected:
            page = document[number - 1]
            try:
                textpage = page.get_textpage()
                try:
                    text = textpage.get_text_bounded()
                finally:
                    textpage.close()
                label = f"{filename}, page {number}"
                if text.strip():
                    parts.append(_text_part(label, text))
                elif mode == "text":
                    raise FileInputError(
                        f"Page {number} has no selectable text. Use pdf_mode='text_and_images'."
                    )
                else:
                    parts.append(_text_part(label, "Read the following scanned page image."))
                if mode == "text_and_images":
                    width, height = page.get_size()
                    if width <= 0 or height <= 0:
                        raise FileInputError(f"Page {number} has invalid dimensions.")
                    # PDF points are 1/72 inch: scale 2 is 144 DPI, capped before allocation.
                    scale = min(2.0, MAX_IMAGE_DIMENSION / max(width, height))
                    bitmap = page.render(scale=scale)
                    try:
                        image = bitmap.to_pil()
                        try:
                            parts.append(_image_part(image, image_module))
                        finally:
                            image.close()
                    finally:
                        bitmap.close()
                _check_prepared_size(parts, limit)
            finally:
                page.close()
    return parts


def _docx_text(data: bytes) -> str:
    docx = _dependency("docx")
    document = docx.Document(io.BytesIO(data))

    def visit(container: Any) -> str:
        fragments: list[str] = []
        for block in container.iter_inner_content():
            if hasattr(block, "rows"):
                for row in block.rows:
                    fragments.append("\t".join(visit(cell) for cell in row.cells))
            else:
                fragments.append(block.text)
        return "\n".join(fragments)

    return visit(document)


def _xlsx_text(
    data: bytes, sheets: Sequence[str] | None, cell_range: str | None, limit: int
) -> str:
    openpyxl = _dependency("openpyxl")
    workbook = openpyxl.load_workbook(
        io.BytesIO(data), read_only=True, data_only=False, keep_links=False
    )
    try:
        selected = list(sheets) if sheets is not None else workbook.sheetnames
        if not selected or len(set(selected)) != len(selected):
            raise FileInputError("Choose at least one sheet; sheet names must be unique.")
        missing = set(selected) - set(workbook.sheetnames)
        if missing:
            raise FileInputError(f"Unknown sheets: {', '.join(sorted(missing))}.")
        bounds: tuple[int, int, int, int] | None = None
        if cell_range is not None:
            parsed = openpyxl.utils.cell.range_boundaries(cell_range)
            if any(value is None or value <= 0 for value in parsed):
                raise FileInputError("cell_range must be a finite rectangle, such as A1:D20.")
            bounds = tuple(parsed)
            if bounds[0] > bounds[2] or bounds[1] > bounds[3]:
                raise FileInputError("cell_range must be an ordered rectangle, such as A1:D20.")
        fragments = []
        size = 0
        cells = 0
        has_value = False
        for name in selected:
            sheet = workbook[name]
            fragments.append(f"Sheet: {name}")
            if bounds:
                min_col, min_row, max_col, max_row = bounds
            else:
                # Producers sometimes omit or understate the dimension element.
                # Derive actual bounds so an unselected workbook is never truncated.
                sheet.reset_dimensions()
                sheet.calculate_dimension(force=True)
                min_col, min_row = 1, 1
                max_col, max_row = sheet.max_column or 1, sheet.max_row or 1
            if (max_col - min_col + 1) * (max_row - min_row + 1) + cells > 100_000:
                raise FileInputError("Select at most 100,000 cells with sheets and cell_range.")
            for row in sheet.iter_rows(
                min_row=min_row, max_row=max_row, min_col=min_col, max_col=max_col
            ):
                values = []
                for cell in row:
                    cells += 1
                    if cell.value is not None:
                        has_value = True
                        values.append(f"{cell.coordinate}={cell.value}")
                line = "\t".join(values)
                size += len(line.encode("utf-8"))
                if size > limit:
                    raise FileInputError("Extracted workbook is too large. Select fewer cells.")
                if line:
                    fragments.append(line)
        if not has_value:
            raise FileInputError("The selected workbook cells are empty.")
        return "\n".join(fragments)
    finally:
        workbook.close()


@dataclass(frozen=True)
class FileInput:
    """Prepared local content. No upload, network request, or model call occurs here."""

    filename: str
    _parts: list[dict[str, Any]] = field(repr=False)

    @classmethod
    def from_path(
        cls,
        path: str | Path,
        *,
        encoding: str = "utf-8",
        pages: Sequence[int] | None = None,
        pdf_mode: Literal["text_and_images", "text"] = "text_and_images",
        sheets: Sequence[str] | None = None,
        cell_range: str | None = None,
        max_source_bytes: int = DEFAULT_MAX_SOURCE_BYTES,
    ) -> "FileInput":
        """Read at most max_source_bytes from a regular local file."""
        source = Path(path)
        if type(max_source_bytes) is not int or max_source_bytes <= 0:
            raise FileInputError("max_source_bytes must be a positive integer.")
        try:
            if not source.is_file():
                raise FileInputError("The source must be a readable regular file.")
            with source.open("rb") as stream:
                data = stream.read(max_source_bytes + 1)
        except OSError as exc:
            raise FileInputError(f"Cannot read {source.name}.") from exc
        return cls.from_bytes(
            data,
            filename=source.name,
            encoding=encoding,
            pages=pages,
            pdf_mode=pdf_mode,
            sheets=sheets,
            cell_range=cell_range,
            max_source_bytes=max_source_bytes,
        )

    @classmethod
    def from_bytes(
        cls,
        data: bytes,
        *,
        filename: str,
        encoding: str = "utf-8",
        pages: Sequence[int] | None = None,
        pdf_mode: Literal["text_and_images", "text"] = "text_and_images",
        sheets: Sequence[str] | None = None,
        cell_range: str | None = None,
        max_source_bytes: int = DEFAULT_MAX_SOURCE_BYTES,
    ) -> "FileInput":
        """Prepare bytes; filename supplies the format and an application-visible label."""
        if type(max_source_bytes) is not int or max_source_bytes <= 0:
            raise FileInputError("max_source_bytes must be a positive integer.")
        if not isinstance(data, bytes) or not data:
            raise FileInputError("Provide nonempty bytes.")
        if len(data) > max_source_bytes:
            raise FileInputError(
                f"Source exceeds {max_source_bytes:,} bytes. Split the file explicitly."
            )
        filename = Path(filename.replace("\\", "/")).name
        extension = Path(filename).suffix.lower()
        if extension not in _EXTENSIONS:
            raise FileInputError(f"Unsupported file type {extension!r}.")
        if pdf_mode not in {"text_and_images", "text"}:
            raise FileInputError("pdf_mode must be 'text_and_images' or 'text'.")
        if (pages is not None or pdf_mode != "text_and_images") and extension != ".pdf":
            raise FileInputError("pages and pdf_mode apply only to PDFs.")
        if (sheets is not None or cell_range is not None) and extension != ".xlsx":
            raise FileInputError("sheets and cell_range apply only to XLSX workbooks.")
        try:
            if extension in _TEXT_EXTENSIONS:
                text = data.decode(encoding).removeprefix("\ufeff")
                if extension == ".json":
                    json.loads(text)
                elif extension == ".csv":
                    for _row in csv.reader(io.StringIO(text), strict=True):
                        pass
                parts = [_text_part(filename, text)]
            elif extension in _IMAGE_EXTENSIONS:
                image_module = _dependency("PIL.Image")
                with warnings.catch_warnings():
                    warnings.simplefilter("error", image_module.DecompressionBombWarning)
                    with image_module.open(io.BytesIO(data)) as image:
                        if image.format not in {"PNG", "JPEG", "WEBP", "GIF"}:
                            raise FileInputError(
                                "Only PNG, JPEG, WebP, and static GIF are supported."
                            )
                        if getattr(image, "is_animated", False):
                            raise FileInputError("Animated images are unsupported. Select a frame.")
                        parts = [_text_part(filename, "Read the following image.")]
                        parts.append(_image_part(image, image_module))
            elif extension == ".pdf":
                parts = _pdf_parts(data, filename, pages, pdf_mode, max_source_bytes)
            else:
                _office_archive(data, max_source_bytes)
                text = (
                    _docx_text(data)
                    if extension == ".docx"
                    else _xlsx_text(data, sheets, cell_range, max_source_bytes)
                )
                parts = [_text_part(filename, text)]
            _check_prepared_size(parts, max_source_bytes)
            return cls(filename=filename, _parts=parts)
        except FileInputError:
            raise
        except Exception as exc:
            raise FileInputError(
                f"Cannot prepare {filename}: invalid, encrypted, or incorrectly encoded file."
            ) from exc

    def to_responses_content(self) -> list[dict[str, Any]]:
        """Return independent Responses input_text/input_image content dictionaries."""
        return copy.deepcopy(self._parts)

    def to_chat_content(self) -> list[dict[str, Any]]:
        """Return independent Chat Completions text/image_url content dictionaries."""
        return [
            {"type": "text", "text": part["text"]}
            if part["type"] == "input_text"
            else {
                "type": "image_url",
                "image_url": {"url": part["image_url"], "detail": part["detail"]},
            }
            for part in self._parts
        ]
