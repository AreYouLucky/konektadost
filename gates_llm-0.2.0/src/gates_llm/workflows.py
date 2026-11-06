import time
from collections.abc import Callable, Iterable
from dataclasses import dataclass, field
from typing import Any

from pydantic import BaseModel


@dataclass(frozen=True, slots=True)
class PromptTemplate:
    template: str

    def render(self, **values: Any) -> str:
        return self.template.format_map(values)


@dataclass(frozen=True, slots=True)
class Tool:
    name: str
    function: Callable[..., Any]
    description: str

    def call(self, arguments: dict[str, Any]) -> Any:
        return self.function(**arguments)


class ToolRegistry:
    def __init__(self, tools: Iterable[Tool] = ()) -> None:
        self._tools: dict[str, Tool] = {}
        for tool in tools:
            self.register(tool)

    def register(self, tool: Tool) -> None:
        if tool.name in self._tools:
            raise ValueError(f"Tool {tool.name!r} is already registered")
        self._tools[tool.name] = tool

    def call(self, name: str, arguments: dict[str, Any]) -> Any:
        tool = self._tools.get(name)
        if tool is None:
            raise ValueError(f"Tool {name!r} is not registered")
        return tool.call(arguments)


@dataclass(frozen=True, slots=True)
class ToolCall:
    name: str
    arguments: dict[str, Any] = field(default_factory=dict)


@dataclass(frozen=True, slots=True)
class ToolResult:
    name: str
    value: Any


class WorkflowCancelledError(RuntimeError):
    pass


@dataclass(slots=True)
class CancellationToken:
    cancelled: bool = False

    def cancel(self) -> None:
        self.cancelled = True

    def raise_if_cancelled(self) -> None:
        if self.cancelled:
            raise WorkflowCancelledError("Workflow execution was cancelled")


@dataclass(frozen=True, slots=True)
class TraceEvent:
    step_index: int
    step_name: str
    elapsed_seconds: float


TraceHook = Callable[[TraceEvent], None]


@dataclass(frozen=True, slots=True)
class Branch:
    predicate: Callable[[Any], bool]
    when_true: Callable[[Any], Any]
    when_false: Callable[[Any], Any]

    def __call__(self, value: Any) -> Any:
        branch = self.when_true if self.predicate(value) else self.when_false
        return branch(value)


class StructuredOutput:
    def __init__(self, model: type[BaseModel]) -> None:
        self.model = model

    def validate(self, value: Any) -> BaseModel:
        return self.model.model_validate(value)


class Workflow:
    def __init__(self, steps: Iterable[Callable[[Any], Any]], *, max_steps: int = 20) -> None:
        self.steps = list(steps)
        self.max_steps = max_steps
        if len(self.steps) > max_steps:
            raise ValueError("Workflow exceeds max_steps")

    def run(
        self,
        value: Any,
        *,
        cancellation: CancellationToken | None = None,
        timeout_seconds: float | None = None,
        trace_hook: TraceHook | None = None,
    ) -> Any:
        if timeout_seconds is not None and timeout_seconds <= 0:
            raise ValueError("timeout_seconds must be positive")
        started = time.monotonic()
        current = value
        for index, step in enumerate(self.steps):
            _check_execution_limits(cancellation, timeout_seconds, started)
            current = step(current)
            _check_execution_limits(cancellation, timeout_seconds, started)
            if trace_hook:
                trace_hook(
                    TraceEvent(
                        step_index=index,
                        step_name=getattr(step, "__name__", type(step).__name__),
                        elapsed_seconds=time.monotonic() - started,
                    )
                )
        return current


class ToolLoop:
    def __init__(self, registry: ToolRegistry, *, max_iterations: int = 8) -> None:
        if max_iterations <= 0:
            raise ValueError("max_iterations must be positive")
        self.registry = registry
        self.max_iterations = max_iterations

    def run(
        self,
        initial_state: Any,
        next_call: Callable[[Any, list[ToolResult]], ToolCall | None],
        *,
        cancellation: CancellationToken | None = None,
        timeout_seconds: float | None = None,
        trace_hook: TraceHook | None = None,
    ) -> list[ToolResult]:
        if timeout_seconds is not None and timeout_seconds <= 0:
            raise ValueError("timeout_seconds must be positive")
        started = time.monotonic()
        results: list[ToolResult] = []
        for index in range(self.max_iterations):
            _check_execution_limits(cancellation, timeout_seconds, started)
            call = next_call(initial_state, list(results))
            if call is None:
                return results
            value = self.registry.call(call.name, call.arguments)
            results.append(ToolResult(name=call.name, value=value))
            _check_execution_limits(cancellation, timeout_seconds, started)
            if trace_hook:
                trace_hook(
                    TraceEvent(
                        step_index=index,
                        step_name=f"tool:{call.name}",
                        elapsed_seconds=time.monotonic() - started,
                    )
                )
        raise RuntimeError("Tool loop reached max_iterations before completion")


def _check_execution_limits(
    cancellation: CancellationToken | None,
    timeout_seconds: float | None,
    started: float,
) -> None:
    if cancellation:
        cancellation.raise_if_cancelled()
    if (
        timeout_seconds is not None
        and time.monotonic() - started >= timeout_seconds
    ):
        raise TimeoutError("Workflow execution exceeded timeout_seconds")
