from fastapi import APIRouter, HTTPException, Depends
from typing import List
from apps.ai.src.schemas.tools import (
    ToolDefinition,
    ToolExecutionRequest,
    ToolExecutionResult,
)
from apps.ai.src.tools.registry import registry
from apps.ai.src.auth.service_auth import require_verified_claims, VerifiedClaims

router = APIRouter(prefix="/tools")

@router.get("", response_model=List[ToolDefinition])
async def list_tools():
    """
    List all registered typed tools that AI can invoke.
    """
    return registry.list_tools()

@router.post("/execute", response_model=ToolExecutionResult)
async def execute_tool(
    request: ToolExecutionRequest,
    claims: VerifiedClaims = Depends(require_verified_claims),
):
    """
    Execute a typed tool with server-side permission verification and audit logging.
    Enforces service authentication, verified claims, scope isolation, and anti-forgery.
    """
    # 1. Reject forged organization scope
    if request.organization_id and request.organization_id != claims.organization_id:
        raise HTTPException(status_code=403, detail="Scope mismatch: cannot switch organization.")

    # 2. Reject forged entity scope
    if request.entity_id and claims.entity_id and request.entity_id != claims.entity_id:
        raise HTTPException(status_code=403, detail="Scope mismatch: cannot switch entity.")

    # 3. Reject forged user impersonation
    if request.user_id and request.user_id != claims.user_id:
        raise HTTPException(status_code=403, detail="Identity mismatch: cannot impersonate user.")

    # 4. Authoritatively assign server-verified claims
    request.organization_id = claims.organization_id
    request.entity_id = claims.entity_id or request.entity_id or ""
    request.user_id = claims.user_id
    request.user_permissions = claims.user_permissions  # Server-derived from verified claims

    try:
        return await registry.execute(request)
    except ValueError as e:
        raise HTTPException(status_code=403, detail=str(e))
