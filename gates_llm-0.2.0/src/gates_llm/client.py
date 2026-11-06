import json
import os
import time
import uuid
from collections.abc import Iterator
from types import TracebackType
from typing import Any, TypeVar, cast

import httpx
from typing_extensions import Self  # noqa: UP035 - SDK supports Python 3.10

from .exceptions import ERROR_TYPES, GatesError, ProviderUnavailableError, RequestTooLargeError
from .models import (
    ChatCompletionRequest,
    ChatCompletionResult,
    GatesModel,
    ModelList,
    ResponseRequest,
    ResponseResult,
    StreamEvent,
)

ResponseType = TypeVar("ResponseType", bound=GatesModel)


def _encode_payload(payload: dict[str, Any], limit: int, request_id: str) -> bytes:
    body = json.dumps(
        payload, ensure_ascii=False, separators=(",", ":"), allow_nan=False
    ).encode("utf-8")
    if len(body) > limit:
        raise RequestTooLargeError(
            "The encoded request is too large. Select fewer pages, sheets, or cells, "
            "or split the input explicitly.",
            code="request_too_large",
            status_code=413,
            correlation_id=request_id,
            details={"request_bytes": len(body), "max_request_bytes": limit},
        )
    return body


def _raise_for_gates_error(response: httpx.Response) -> None:
    if response.status_code < 400:
        return
    try:
        body = response.json()
    except ValueError:
        response.raise_for_status()
        return
    error = body.get("error") or {}
    code = str(error.get("code", "gates_error"))
    error_type = ERROR_TYPES.get(code, GatesError)
    raise error_type(
        str(error.get("message", "GATES request failed.")),
        code=code,
        status_code=response.status_code,
        correlation_id=error.get("correlation_id")
        or response.headers.get("X-Request-ID"),
        details=error.get("details"),
    )


class _Responses:
    def __init__(self, client: "GatesClient") -> None:
        self._client = client

    def create(
        self,
        *,
        model: str,
        input: Any,
        max_output_tokens: int,
        request_id: str | None = None,
        **kwargs: Any,
    ) -> ResponseResult:
        request = ResponseRequest.model_validate(
            {
                "model": model,
                "input": input,
                "max_output_tokens": max_output_tokens,
                **kwargs,
            }
        )
        response = self._client._post(
            "/v1/responses",
            request.payload(),
            request_id=request_id,
        )
        return self._client._typed_response(response, ResponseResult)

    def stream(
        self,
        *,
        model: str,
        input: Any,
        max_output_tokens: int,
        request_id: str | None = None,
        **kwargs: Any,
    ) -> Iterator[StreamEvent]:
        request = ResponseRequest.model_validate(
            {
                "model": model,
                "input": input,
                "max_output_tokens": max_output_tokens,
                "stream": True,
                **kwargs,
            }
        )
        yield from self._client._stream(
            "/v1/responses",
            request.payload(),
            request_id=request_id,
        )


class _ChatCompletions:
    def __init__(self, client: "GatesClient") -> None:
        self._client = client

    def create(
        self,
        *,
        model: str,
        messages: list[dict[str, Any]],
        max_tokens: int,
        request_id: str | None = None,
        **kwargs: Any,
    ) -> ChatCompletionResult:
        request = ChatCompletionRequest.model_validate(
            {
                "model": model,
                "messages": messages,
                "max_completion_tokens": max_tokens,
                **kwargs,
            }
        )
        response = self._client._post(
            "/v1/chat/completions",
            request.payload(),
            request_id=request_id,
        )
        return self._client._typed_response(response, ChatCompletionResult)

    def stream(
        self,
        *,
        model: str,
        messages: list[dict[str, Any]],
        max_tokens: int,
        request_id: str | None = None,
        **kwargs: Any,
    ) -> Iterator[StreamEvent]:
        request = ChatCompletionRequest.model_validate(
            {
                "model": model,
                "messages": messages,
                "max_completion_tokens": max_tokens,
                "stream": True,
                **kwargs,
            }
        )
        yield from self._client._stream(
            "/v1/chat/completions",
            request.payload(),
            request_id=request_id,
        )


class _Chat:
    def __init__(self, client: "GatesClient") -> None:
        self.completions = _ChatCompletions(client)


class _Models:
    def __init__(self, client: "GatesClient") -> None:
        self._client = client

    def list(self, *, request_id: str | None = None) -> ModelList:
        request_id = request_id or uuid.uuid4().hex
        response = self._client._safe_get(
            "/v1/models",
            request_id=request_id,
        )
        return self._client._typed_response(response, ModelList)


class GatesClient:
    def __init__(
        self,
        *,
        api_key: str,
        base_url: str,
        timeout: float = 180.0,
        max_safe_retries: int = 2,
        retry_backoff_seconds: float = 0.1,
        transport: httpx.BaseTransport | None = None,
        max_request_bytes: int = 2_000_000,
    ) -> None:
        if max_safe_retries < 0:
            raise ValueError("max_safe_retries cannot be negative")
        if type(max_request_bytes) is not int or max_request_bytes <= 0:
            raise ValueError("max_request_bytes must be a positive integer")
        self._max_request_bytes = max_request_bytes
        self._client = httpx.Client(
            base_url=base_url.rstrip("/"),
            headers={"Authorization": f"Bearer {api_key}", "Content-Type": "application/json"},
            timeout=httpx.Timeout(timeout, connect=5),
            transport=transport,
        )
        self._max_safe_retries = max_safe_retries
        self._retry_backoff_seconds = retry_backoff_seconds
        self.responses = _Responses(self)
        self.chat = _Chat(self)
        self.models = _Models(self)

    @classmethod
    def from_env(cls, **kwargs: Any) -> Self:
        api_key = os.getenv("GATES_API_KEY")
        if not api_key:
            raise ValueError("GATES_API_KEY is required")
        return cls(
            api_key=api_key,
            base_url=os.getenv("GATES_BASE_URL", "http://localhost:18080"),
            **kwargs,
        )

    def _post(
        self,
        path: str,
        payload: dict[str, Any],
        *,
        request_id: str | None = None,
    ) -> httpx.Response:
        request_id = request_id or uuid.uuid4().hex
        body = _encode_payload(payload, self._max_request_bytes, request_id)
        try:
            response = self._client.post(
                path, content=body, headers={"X-Request-ID": request_id}
            )
        except httpx.TransportError as exc:
            raise ProviderUnavailableError(
                "The GATES gateway could not be reached.",
                code="transport_error",
                correlation_id=request_id,
            ) from exc
        _raise_for_gates_error(response)
        return response

    def _stream(
        self,
        path: str,
        payload: dict[str, Any],
        *,
        request_id: str | None = None,
    ) -> Iterator[StreamEvent]:
        request_id = request_id or uuid.uuid4().hex
        body = _encode_payload(payload, self._max_request_bytes, request_id)
        try:
            with self._client.stream(
                "POST",
                path,
                content=body,
                headers={"X-Request-ID": request_id},
            ) as response:
                _raise_for_gates_error(response)
                for line in response.iter_lines():
                    if not line.startswith("data:"):
                        continue
                    data = line.removeprefix("data:").strip()
                    if data == "[DONE]":
                        return
                    event = StreamEvent.model_validate(json.loads(data))
                    event.gates_request_id = (
                        response.headers.get("X-Request-ID") or request_id
                    )
                    yield event
        except httpx.TransportError as exc:
            raise ProviderUnavailableError(
                "The GATES gateway stream was interrupted.",
                code="transport_error",
                correlation_id=request_id,
            ) from exc

    def _safe_get(self, path: str, *, request_id: str) -> httpx.Response:
        for attempt in range(self._max_safe_retries + 1):
            try:
                response = self._client.get(
                    path,
                    headers={"X-Request-ID": request_id},
                )
            except httpx.TransportError as exc:
                if attempt >= self._max_safe_retries:
                    raise ProviderUnavailableError(
                        "The GATES gateway could not be reached.",
                        code="transport_error",
                        correlation_id=request_id,
                    ) from exc
            else:
                if response.status_code not in {502, 503, 504}:
                    _raise_for_gates_error(response)
                    return response
                if attempt >= self._max_safe_retries:
                    _raise_for_gates_error(response)
            time.sleep(self._retry_backoff_seconds * (2**attempt))
        raise RuntimeError("safe GET retry loop exited unexpectedly")

    @staticmethod
    def _typed_response(
        response: httpx.Response,
        model: type[ResponseType],
    ) -> ResponseType:
        result = model.model_validate(cast(dict[str, Any], response.json()))
        result.gates_request_id = response.headers.get("X-Request-ID")
        return result

    def close(self) -> None:
        self._client.close()

    def __enter__(self) -> Self:
        return self

    def __exit__(
        self,
        exc_type: type[BaseException] | None,
        exc_value: BaseException | None,
        traceback: TracebackType | None,
    ) -> None:
        self.close()
