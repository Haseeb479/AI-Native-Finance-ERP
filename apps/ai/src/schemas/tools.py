from pydantic import BaseModel, Field
from typing import Dict, Any, Optional, List
from enum import Enum

class ToolCategory(str, Enum):
    READ = "read"
    DRAFT = "draft"
    ACTION = "action"

class ToolDefinition(BaseModel):
    name: str
    purpose: str
    category: ToolCategory
    required_permission: str
    input_schema: Dict[str, Any]
    output_schema: Dict[str, Any]
    side_effects: bool
    idempotent: bool
    audit_event: str
    failure_behavior: str

class ToolExecutionRequest(BaseModel):
    tool_name: str
    arguments: Dict[str, Any]
    organization_id: str
    entity_id: str
    user_id: str
    user_permissions: List[str]

class ToolExecutionResult(BaseModel):
    tool_name: str
    success: bool
    data: Optional[Dict[str, Any]] = None
    error: Optional[str] = None
    audit_event: str
    execution_time_ms: float
