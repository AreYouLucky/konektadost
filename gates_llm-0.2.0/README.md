# gates-llm 0.2.0

Typed synchronous and asynchronous Python clients for the GATES LLM Suite
gateway. Requires Python 3.10 or newer. Open **Python SDK documentation** from
your authenticated dashboard (`/docs/sdk`) for the complete handbook.

## Install and configure

Download the wheel from your dashboard and verify the displayed SHA-256 digest.

```bash
python -m pip install ./gates_llm-0.2.0-py3-none-any.whl
python -m pip install './gates_llm-0.2.0-py3-none-any.whl[files]'
```

The second command adds optional PDF, image, Word, and spreadsheet support.
Text-file preparation works with the base package.

Set `GATES_API_KEY` and `GATES_BASE_URL` in your environment. The local gateway
default is `http://localhost:18080`; use your deployed **backend origin**, without
`/v1`, outside local development. `from_env()` does not load `.env` automatically;
install `python-dotenv` and call `load_dotenv(".env")` if desired. Never commit or
print credentials.

## Generate text

```python
from gates_llm import GatesClient

with GatesClient.from_env() as client:
    response = client.responses.create(
        model="openai.gpt-6-luna",
        input="Explain retrieval-augmented generation.",
        max_output_tokens=300,
    )
    print(response.output_text)
    print(response.gates_request_id, response.usage)
```

Responses, model discovery, and stream events are typed Pydantic models while
retaining dictionary-style field access for compatibility. Successful responses
expose the gateway correlation ID as `gates_request_id`.

`client.models.list()` discovers available models, boolean capability flags,
and gateway/model limits. For Chat Completions, use
`client.chat.completions.create(messages=[...], model=..., max_tokens=...)`.
The SDK serializes `max_tokens` as `max_completion_tokens`.

Both APIs expose `.stream()`. `AsyncGatesClient` mirrors the methods using
`await`, `async with`, and `async for`. Keep clients open throughout a stream
and consume terminal usage.

## Attach documents and images

```python
from gates_llm import FileInput, GatesClient

document = FileInput.from_path("report.pdf", pages=[1, 2])
with GatesClient.from_env() as client:
    response = client.responses.create(
        model="openai.gpt-6-luna",
        input=[{"role": "user", "content": [
            {"type": "input_text", "text": "Summarize the text and explain the charts."},
            *document.to_responses_content(),
        ]}],
        max_output_tokens=500,
    )
    print(response.output_text)
```

`FileInput.from_bytes(data, filename="report.pdf")` accepts in-memory bytes.
`to_chat_content()` produces Chat Completions parts.

| Format | Preparation |
| --- | --- |
| TXT, Markdown, CSV, JSON | Labeled text, explicit encoding supported |
| PDF | Selected page text and images; scans and charts included |
| DOCX | Body paragraphs and tables in document order |
| XLSX | Selected sheets/cell ranges; literal formulas, no recalculation |
| PNG, JPEG, WebP, static GIF | Bounded inline PNG images |

Preparation runs locally and creates no persistent provider files. PDF pages
are one-based, with at most 20 selected pages per attachment. Use
`pdf_mode="text"` for selectable text only, or
`sheets=["Sales"], cell_range="A1:D20"` for spreadsheets. DOCX/XLSX artwork is not
rendered; export visual pages to PDF when needed.

Source files default to a 20,000,000-byte preparation limit. The **complete
encoded inference request** defaults to 2,000,000 bytes, including base64
overhead and all attachments. Larger sources require explicit selection or
splitting; content is never silently truncated. `FileInputError` describes local
preparation failures; `RequestTooLargeError` identifies request-size failures.

Image-bearing requests reserve against the model's verified context ceiling;
the temporary credit requirement can be much larger than the actual charge.
See the handbook's usage section before processing many visual pages.

## Structured outputs

```python
from pydantic import BaseModel, ConfigDict
from gates_llm import GatesClient

class Ticket(BaseModel):
    model_config = ConfigDict(extra="forbid")
    category: str
    urgent: bool

with GatesClient.from_env() as client:
    response = client.responses.create(
        model="openai.gpt-6-luna",
        input="Classify this ticket: the production dashboard is unavailable.",
        text={"format": {
            "type": "json_schema", "name": "ticket",
            "strict": True, "schema": Ticket.model_json_schema(),
        }},
        max_output_tokens=300,
    )
    if response.status == "completed" and response.output_text:
        print(Ticket.model_validate_json(response.output_text))
```

For Chat Completions, put the schema in `response_format.json_schema`. Check
refusals and incomplete output before parsing and handle validation errors.
`StructuredOutput(Ticket).validate(value)` validates Python data locally; it
does not configure model generation.

## Reliability and workflows

The SDK retries only safe model-discovery `GET` requests. Generation and
streaming calls are never automatically retried because provider execution and
billing may be ambiguous. Transport failures use structured
`ProviderUnavailableError` exceptions and preserve the caller's request ID.

Each intentional generation needs a new request ID. Reusing an ID returns
`duplicate_request`; it does not replay the earlier result. Always supply a
positive output-token ceiling and inspect the dashboard for ambiguous outcomes.

Workflow helpers include prompt templates, branching, structured-output
validation, explicit tool registries, bounded tool loops, cooperative
cancellation/time limits, and metadata-only trace hooks. Tools run only when
registered and invoked by application code; the SDK never executes generated
code automatically.

The handbook covers every public helper, method signature, default, error
class, typed result, stream event, local transport, retrieval recipe, and bounded
concurrency pattern. Audio/video, embeddings, generated images, persistent
uploads, hosted tools, stored responses, and background jobs are not exposed by
this SDK release.

## Upgrade from 0.1.0

Existing client calls continue to work. New in 0.2.0: local file preparation,
request-size preflight, `RequestTooLargeError`, typed model capability/limit
metadata, and normalization of standard Responses text content. Install the
new wheel and restart notebook kernels before using new helpers.
