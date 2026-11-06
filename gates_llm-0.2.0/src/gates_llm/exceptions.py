from typing import Any


class GatesError(Exception):
    def __init__(
        self,
        message: str,
        *,
        code: str = "gates_error",
        status_code: int | None = None,
        correlation_id: str | None = None,
        details: dict[str, Any] | None = None,
    ) -> None:
        super().__init__(message)
        self.code = code
        self.status_code = status_code
        self.correlation_id = correlation_id
        self.details = details or {}


class AuthenticationError(GatesError):
    pass


class BudgetExhaustedError(GatesError):
    pass


class ModelNotAllowedError(GatesError):
    pass


class RateLimitError(GatesError):
    pass


class ProviderUnavailableError(GatesError):
    pass


class RequestTooLargeError(GatesError):
    pass


class FileInputError(ValueError):
    """A local attachment could not be prepared; no inference was submitted."""


ERROR_TYPES: dict[str, type[GatesError]] = {
    "authentication_required": AuthenticationError,
    "invalid_api_key": AuthenticationError,
    "budget_exhausted": BudgetExhaustedError,
    "model_not_allowed": ModelNotAllowedError,
    "rate_limit_exceeded": RateLimitError,
    "provider_unavailable": ProviderUnavailableError,
    "provider_usage_missing": ProviderUnavailableError,
    "request_too_large": RequestTooLargeError,
}
