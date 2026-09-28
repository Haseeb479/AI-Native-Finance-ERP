import time
import asyncio
from typing import Dict, Callable, Any, List
from apps.ai.src.schemas.tools import (
    ToolDefinition,
    ToolCategory,
    ToolExecutionRequest,
    ToolExecutionResult,
)
from apps.ai.src.schemas.guardrails import assert_no_direct_mutation
from apps.ai.src.auth.service_auth import verify_approval_token

class ToolRegistry:
    """
    Central, permission-enforced tool registry.
    Ensures that AI can ONLY call explicitly registered, typed tools with
    server-enforced permissions, tenant scopes, approval gates, and bounded execution (P1-09, P1-11).
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
        
        # 1. Enforce guardrails: prohibit any direct balance mutation or arbitrary SQL
        assert_no_direct_mutation(request.tool_name)
        
        # 2. Verify tool existence
        if request.tool_name not in self._tools:
            return ToolExecutionResult(
                tool_name=request.tool_name,
                success=False,
                status="failed",
                error=f"Tool '{request.tool_name}' not found.",
                audit_event="tool_not_found",
                execution_time_ms=(time.time() - start_time) * 1000,
            )

        tool = self._tools[request.tool_name]

        # 3. P1-11: Tenant and Entity Scope Enforcement
        if tool.tenant_scope and not request.organization_id:
            return ToolExecutionResult(
                tool_name=request.tool_name,
                success=False,
                status="failed",
                error=f"Tenant scope violation: tool '{tool.name}' requires explicit organization_id.",
                audit_event="tool_tenant_scope_violation",
                execution_time_ms=(time.time() - start_time) * 1000,
            )

        if tool.entity_scope and not request.entity_id:
            return ToolExecutionResult(
                tool_name=request.tool_name,
                success=False,
                status="failed",
                error=f"Entity scope violation: tool '{tool.name}' requires explicit entity_id.",
                audit_event="tool_entity_scope_violation",
                execution_time_ms=(time.time() - start_time) * 1000,
            )

        # 4. P1-11: Verify server-side permission derived from verified service token
        user_perms = request.user_permissions if request.user_permissions is not None else []
        required_perm = tool.permission or tool.required_permission
        if required_perm not in user_perms and "*" not in user_perms:
            return ToolExecutionResult(
                tool_name=request.tool_name,
                success=False,
                status="failed",
                error=f"Permission denied. Required: '{required_perm}'.",
                audit_event="tool_permission_denied",
                execution_time_ms=(time.time() - start_time) * 1000,
            )

        # 5. P1-09: AI Approval Gate for side-effects
        if tool.side_effects and tool.approval_required:
            if not request.approval_token:
                # Intercept execution and emit approval requirement gate
                return ToolExecutionResult(
                    tool_name=request.tool_name,
                    success=True,
                    status="pending_approval",
                    requires_approval=True,
                    approval_context={
                        "tool_name": tool.name,
                        "arguments": request.arguments,
                        "required_permission": required_perm,
                        "purpose": tool.purpose,
                        "organization_id": request.organization_id,
                        "entity_id": request.entity_id,
                        "side_effects": tool.side_effects,
                    },
                    data={
                        "gate_status": "pending_human_review",
                        "message": f"Tool '{tool.name}' produces side effects and requires explicit human approval before execution.",
                        "action_summary": tool.purpose,
                    },
                    audit_event=f"{tool.audit_event}_approval_gated",
                    execution_time_ms=(time.time() - start_time) * 1000,
                )
            else:
                # Cryptographically verify human approval token
                try:
                    verify_approval_token(
                        token=request.approval_token,
                        expected_tool_name=tool.name,
                        arguments=request.arguments,
                        organization_id=request.organization_id,
                    )
                except Exception as e:
                    return ToolExecutionResult(
                        tool_name=request.tool_name,
                        success=False,
                        status="failed",
                        error=f"Approval gate verification failed: {str(e)}",
                        audit_event="tool_approval_verification_failed",
                        execution_time_ms=(time.time() - start_time) * 1000,
                    )

        # 6. P1-11: Execute tool handler with timeout and bounded retries
        handler = self._handlers[request.tool_name]
        max_retries = tool.retry_policy.get("max_retries", 1) if tool.retry_policy else 1
        backoff_sec = tool.retry_policy.get("backoff_seconds", 0.5) if tool.retry_policy else 0.5

        last_error = None
        for attempt in range(max_retries + 1):
            try:
                result_data = await asyncio.wait_for(
                    handler(request.arguments, request),
                    timeout=tool.timeout_seconds,
                )
                
                return ToolExecutionResult(
                    tool_name=request.tool_name,
                    success=True,
                    status="completed",
                    requires_approval=False,
                    data=result_data,
                    evidence={"tool": tool.name, "audit_event": tool.audit_event, "attempt": attempt + 1},
                    audit_event=tool.audit_event,
                    execution_time_ms=(time.time() - start_time) * 1000,
                )
            except asyncio.TimeoutError:
                last_error = f"Tool execution timed out after {tool.timeout_seconds}s."
                break
            except Exception as e:
                last_error = str(e)
                if attempt < max_retries:
                    await asyncio.sleep(backoff_sec * (attempt + 1))

        return ToolExecutionResult(
            tool_name=request.tool_name,
            success=False,
            status="failed",
            error=last_error,
            audit_event="tool_execution_failed",
            execution_time_ms=(time.time() - start_time) * 1000,
        )

registry = ToolRegistry()
