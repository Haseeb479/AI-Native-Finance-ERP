from pydantic import BaseModel, Field, model_validator
from typing import Dict, Any, Optional, List
from enum import Enum

class ToolCategory(str, Enum):
    READ = "read"
    DRAFT = "draft"
    ACTION = "action"

class ToolDefinition(BaseModel):
    """
    Every tool must explicitly declare its full security metadata and execution policy (P1-11).
    """
    name: str
    purpose: str
    category: ToolCategory
    required_permission: str
    permission: Optional[str] = None
    input_schema: Dict[str, Any]
    output_schema: Dict[str, Any]
    tenant_scope: bool = True
    entity_scope: bool = False
    side_effects: bool = False
    approval_required: bool = False
    idempotent: bool = True
    audit_event: str
    failure_behavior: str = "Return error result without altering state"
    timeout_seconds: float = 30.0
    retry_policy: Dict[str, Any] = Field(default_factory=lambda: {"max_retries": 2, "backoff_seconds": 1.0})

    @model_validator(mode="after")
    def sync_permission_fields(self):
        if not self.permission:
            self.permission = self.required_permission
        elif not self.required_permission:
            self.required_permission = self.permission
        return self

class ToolExecutionRequest(BaseModel):
    tool_name: str
    arguments: Dict[str, Any]
    organization_id: Optional[str] = None
    entity_id: Optional[str] = None
    user_id: Optional[str] = None
    user_permissions: Optional[List[str]] = None
    approval_token: Optional[str] = None
    idempotency_key: Optional[str] = None

class ToolExecutionResult(BaseModel):
    tool_name: str
    success: bool
    status: str = "completed"  # completed, pending_approval, failed
    requires_approval: bool = False
    data: Optional[Dict[str, Any]] = None
    approval_context: Optional[Dict[str, Any]] = None
    evidence: Optional[Dict[str, Any]] = None
    error: Optional[str] = None
    audit_event: str
    execution_time_ms: float
