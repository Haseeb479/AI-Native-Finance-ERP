from fastapi import APIRouter, HTTPException
from typing import List
from apps.ai.src.schemas.tools import (
    ToolDefinition,
    ToolExecutionRequest,
    ToolExecutionResult,
)
from apps.ai.src.tools.registry import registry

router = APIRouter(prefix="/tools")

@router.get("", response_model=List[ToolDefinition])
async def list_tools():
    """
    List all registered typed tools that AI can invoke.
    """
    return registry.list_tools()

@router.post("/execute", response_model=ToolExecutionResult)
async def execute_tool(request: ToolExecutionRequest):
    """
    Execute a typed tool with server-side permission verification and audit logging.
    """
    try:
        return await registry.execute(request)
    except ValueError as e:
        raise HTTPException(status_code=403, detail=str(e))
