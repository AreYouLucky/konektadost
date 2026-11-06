from typing import Any

from pydantic import BaseModel, ConfigDict, Field, model_validator


class GatesModel(BaseModel):
    model_config = ConfigDict(extra="allow")

    gates_request_id: str | None = Field(default=None, exclude=True)

    def __getitem__(self, key: str) -> Any:
        if key in type(self).model_fields:
            return getattr(self, key)
        extra = self.__pydantic_extra__ or {}
        return extra[key]

    def get(self, key: str, default: Any = None) -> Any:
        try:
            return self[key]
        except KeyError:
            return default


class ResponseRequest(BaseModel):
    model_config = ConfigDict(extra="allow")

    model: str = Field(min_length=1)
    input: Any
    max_output_tokens: int = Field(gt=0)
    stream: bool = False

    def payload(self) -> dict[str, Any]:
        return self.model_dump(exclude_none=True)


class ChatMessage(BaseModel):
    model_config = ConfigDict(extra="allow")

    role: str = Field(min_length=1)
    content: Any = None


class ChatCompletionRequest(BaseModel):
    model_config = ConfigDict(extra="allow")

    model: str = Field(min_length=1)
    messages: list[ChatMessage]
    max_completion_tokens: int = Field(gt=0)
    stream: bool = False

    def payload(self) -> dict[str, Any]:
        return self.model_dump(exclude_none=True)


class TokenUsage(GatesModel):
    input_tokens: int | None = None
    output_tokens: int | None = None
    prompt_tokens: int | None = None
    completion_tokens: int | None = None
    total_tokens: int | None = None
    cache_read_input_tokens: int | None = None
    cache_write_input_tokens: int | None = None


class ResponseResult(GatesModel):
    id: str | None = None
    object: str | None = None
    status: str | None = None
    model: str | None = None
    output_text: str | None = None
    output: list[dict[str, Any]] = Field(default_factory=list)
    usage: TokenUsage | None = None

    @model_validator(mode="before")
    @classmethod
    def normalize_output_text(cls, value: Any) -> Any:
        if isinstance(value, dict) and "output_text" not in value:
            texts = [
                part["text"]
                for item in value.get("output", [])
                if isinstance(item, dict) and item.get("type") == "message"
                for part in item.get("content", [])
                if isinstance(part, dict)
                and part.get("type") == "output_text"
                and isinstance(part.get("text"), str)
            ]
            if texts:
                value = {**value, "output_text": "".join(texts)}
        return value


class ChatChoice(GatesModel):
    index: int
    message: ChatMessage | None = None
    finish_reason: str | None = None


class ChatCompletionResult(GatesModel):
    id: str | None = None
    object: str | None = None
    model: str | None = None
    choices: list[ChatChoice] = Field(default_factory=list)
    usage: TokenUsage | None = None


class StreamEvent(GatesModel):
    type: str | None = None


class ModelInfo(GatesModel):
    id: str
    object: str = "model"
    owned_by: str | None = None
    capabilities: dict[str, bool] = Field(default_factory=dict)
    limits: dict[str, int] = Field(default_factory=dict)


class ModelList(GatesModel):
    object: str = "list"
    data: list[ModelInfo] = Field(default_factory=list)
