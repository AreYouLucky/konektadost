import asyncio
import json
import os
import uuid
from collections.abc import AsyncIterator
from types import TracebackType
from typing import Any

import httpx
from typing_extensions import Self  # noqa: UP035 - SDK supports Python 3.10

from .client import GatesClient, _encode_payload, _raise_for_gates_error
from .exceptions import ProviderUnavailableError
from .models import (
    ChatCompletionRequest,
    ChatCompletionResult,
    ModelList,
    ResponseRequest,
    ResponseResult,
    StreamEvent,
)


class _AsyncResponses:
    def __init__(self, client: "AsyncGatesClient") -> None:
        self._client = client

    async def create(
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
        response = await self._client._post(
            "/v1/responses",
            request.payload(),
            request_id=request_id,
        )
        return GatesClient._typed_response(response, ResponseResult)

    async def stream(
        self,
        *,
        model: str,
        input: Any,
        max_output_tokens: int,
        request_id: str | None = None,
        **kwargs: Any,
    ) -> AsyncIterator[StreamEvent]:
        request = ResponseRequest.model_validate(
            {
                "model": model,
                "input": input,
                "max_output_tokens": max_output_tokens,
                "stream": True,
                **kwargs,
            }
        )
        async for event in self._client._stream(
            "/v1/responses",
            request.payload(),
            request_id=request_id,
        ):
            yield event


class _AsyncChatCompletions:
    def __init__(self, client: "AsyncGatesClient") -> None:
        self._client = client

    async def create(
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
        response = await self._client._post(
            "/v1/chat/completions",
            request.payload(),
            request_id=request_id,
        )
        return GatesClient._typed_response(response, ChatCompletionResult)

    async def stream(
        self,
        *,
        model: str,
        messages: list[dict[str, Any]],
        max_tokens: int,
        request_id: str | None = None,
        **kwargs: Any,
    ) -> AsyncIterator[StreamEvent]:
        request = ChatCompletionRequest.model_validate(
            {
                "model": model,
                "messages": messages,
                "max_completion_tokens": max_tokens,
                "stream": True,
                **kwargs,
            }
        )
        async for event in self._client._stream(
            "/v1/chat/completions",
            request.payload(),
            request_id=request_id,
        ):
            yield event


class _AsyncChat:
    def __init__(self, client: "AsyncGatesClient") -> None:
        self.completions = _AsyncChatCompletions(client)


class _AsyncModels:
    def __init__(self, client: "AsyncGatesClient") -> None:
        self._client = client

    async def list(self, *, request_id: str | None = None) -> ModelList:
        response = await self._client._safe_get(
            "/v1/models",
            request_id=request_id or uuid.uuid4().hex,
        )
        return GatesClient._typed_response(response, ModelList)


class AsyncGatesClient:
    def __init__(
        self,
        *,
        api_key: str,
        base_url: str,
        timeout: float = 180.0,
        max_safe_retries: int = 2,
        retry_backoff_seconds: float = 0.1,
        transport: httpx.AsyncBaseTransport | None = None,
        max_request_bytes: int = 2_000_000,
    ) -> None:
        if max_safe_retries < 0:
            raise ValueError("max_safe_retries cannot be negative")
        if type(max_request_bytes) is not int or max_request_bytes <= 0:
            raise ValueError("max_request_bytes must be a positive integer")
        self._max_request_bytes = max_request_bytes
        self._client = httpx.AsyncClient(
            base_url=base_url.rstrip("/"),
            headers={"Authorization": f"Bearer {api_key}", "Content-Type": "application/json"},
            timeout=httpx.Timeout(timeout, connect=5),
            transport=transport,
        )
        self._max_safe_retries = max_safe_retries
        self._retry_backoff_seconds = retry_backoff_seconds
        self.responses = _AsyncResponses(self)
        self.chat = _AsyncChat(self)
        self.models = _AsyncModels(self)

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

    async def _post(
        self,
        path: str,
        payload: dict[str, Any],
        *,
        request_id: str | None = None,
    ) -> httpx.Response:
        request_id = request_id or uuid.uuid4().hex
        body = _encode_payload(payload, self._max_request_bytes, request_id)
        try:
            response = await self._client.post(
                path,
                content=body,
                headers={"X-Request-ID": request_id},
            )
        except httpx.TransportError as exc:
            raise ProviderUnavailableError(
                "The GATES gateway could not be reached.",
                code="transport_error",
                correlation_id=request_id,
            ) from exc
        _raise_for_gates_error(response)
        return response

    async def _stream(
        self,
        path: str,
        payload: dict[str, Any],
        *,
        request_id: str | None = None,
    ) -> AsyncIterator[StreamEvent]:
        request_id = request_id or uuid.uuid4().hex
        body = _encode_payload(payload, self._max_request_bytes, request_id)
        try:
            async with self._client.stream(
                "POST",
                path,
                content=body,
                headers={"X-Request-ID": request_id},
            ) as response:
                _raise_for_gates_error(response)
                async for line in response.aiter_lines():
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

    async def _safe_get(self, path: str, *, request_id: str) -> httpx.Response:
        for attempt in range(self._max_safe_retries + 1):
            try:
                response = await self._client.get(
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
            await asyncio.sleep(self._retry_backoff_seconds * (2**attempt))
        raise RuntimeError("safe GET retry loop exited unexpectedly")

    async def close(self) -> None:
        await self._client.aclose()

    async def __aenter__(self) -> Self:
        return self

    async def __aexit__(
        self,
        exc_type: type[BaseException] | None,
        exc_value: BaseException | None,
        traceback: TracebackType | None,
    ) -> None:
        await self.close()
