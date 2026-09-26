import time
from typing import Dict, Callable, Any, List
from apps.ai.src.schemas.tools import (
    ToolDefinition,
    ToolCategory,
    ToolExecutionRequest,
    ToolExecutionResult,
)
from apps.ai.src.schemas.guardrails import assert_no_direct_mutation

class ToolRegistry:
    """
    Central, permission-enforced tool registry.
    Ensures that AI can ONLY call explicitly registered, typed tools.
    """
    def __init__(self):
        self._tools: Dict[str, ToolDefinition] = {}
        self._handlers: Dict[str, Callable[[Dict[str, Any], ToolExecutionRequest], Any]] = {}

    def register(self, definition: ToolDefinition, handler: Callable):
        self._tools[definition.name] = definition
        self._handlers[definition.name] = handler

    def get_tool(self, name: str) -> ToolDefinition:
        if name not in self._tools:
            raise KeyError(f"Tool '{name}' is not registered.")
        return self._tools[name]

    def list_tools(self) -> List[ToolDefinition]:
        return list(self._tools.values())

    async def execute(self, request: ToolExecutionRequest) -> ToolExecutionResult:
        start_time = time.time()
        
        # 1. Enforce guardrails: prohibit any direct mutation or arbitrary SQL
        assert_no_direct_mutation(request.tool_name)
        
        # 2. Verify tool existence
        if request.tool_name not in self._tools:
            return ToolExecutionResult(
                tool_name=request.tool_name,
                success=False,
                error=f"Tool '{request.tool_name}' not found.",
                audit_event="tool_not_found",
                execution_time_ms=(time.time() - start_time) * 1000,
            )

        tool = self._tools[request.tool_name]

        # 3. Verify server-side permission derived from verified service token
        user_perms = request.user_permissions if request.user_permissions is not None else []
        if tool.required_permission not in user_perms and "*" not in user_perms:
            return ToolExecutionResult(
                tool_name=request.tool_name,
                success=False,
                error=f"Permission denied. Required: '{tool.required_permission}'.",
                audit_event="tool_permission_denied",
                execution_time_ms=(time.time() - start_time) * 1000,
            )

        # 4. Execute tool handler
        try:
            handler = self._handlers[request.tool_name]
            result_data = await handler(request.arguments, request)
            
            return ToolExecutionResult(
                tool_name=request.tool_name,
                success=True,
                data=result_data,
                audit_event=tool.audit_event,
                execution_time_ms=(time.time() - start_time) * 1000,
            )
        except Exception as e:
            return ToolExecutionResult(
                tool_name=request.tool_name,
                success=False,
                error=str(e),
                audit_event="tool_execution_failed",
                execution_time_ms=(time.time() - start_time) * 1000,
            )

registry = ToolRegistry()
