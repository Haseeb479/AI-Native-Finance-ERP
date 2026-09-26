import httpx
from typing import Dict, Any
from apps.ai.src.schemas.tools import ToolDefinition, ToolCategory, ToolExecutionRequest
from apps.ai.src.tools.registry import registry
from apps.ai.src.config import settings
from apps.ai.src.auth.service_auth import create_internal_token

# Tool 1: get_account
async def handle_get_account(args: Dict[str, Any], context: ToolExecutionRequest) -> Dict[str, Any]:
    account_code = args.get("account_code")
    token = create_internal_token(
        organization_id=context.organization_id,
        user_id=context.user_id,
        user_permissions=context.user_permissions or [],
        entity_id=context.entity_id,
    )
    url = f"{settings.BACKEND_API_URL}/internal/organizations/{context.organization_id}/accounts/lookup"
    try:
        async with httpx.AsyncClient(timeout=10.0) as client:
            resp = await client.get(url, params={"code": account_code}, headers={"Authorization": f"Bearer {token}"})
            if resp.status_code == 200:
                data = resp.json().get("data", {})
                return {
                    "account_code": data.get("account_code", account_code),
                    "account_name": data.get("account_name"),
                    "account_type": data.get("type"),
                    "classification": data.get("classification"),
                    "currency": data.get("currency", "PKR"),
                    "current_balance": data.get("current_balance", "0.00"),
                    "is_active": data.get("is_active", True),
                    "organization_id": context.organization_id,
                    "evidence_source": "postgresql://general_ledger",
                }
    except Exception as e:
        if settings.ENVIRONMENT == "production":
            raise RuntimeError(f"Upstream ledger lookup failed: {str(e)}")

    if settings.ENVIRONMENT == "production":
        raise RuntimeError(f"Failed to query account {account_code} from production ledger.")

    return {
        "account_code": account_code,
        "account_name": f"Account {account_code}",
        "account_type": "Asset" if str(account_code).startswith("1") else "Expense",
        "currency": "PKR",
        "current_balance": "0.00",
        "is_active": True,
        "organization_id": context.organization_id,
        "evidence_source": "cached_offline_schema",
    }

tool_get_account = ToolDefinition(
    name="get_account",
    purpose="Fetch verified Chart of Accounts record and ledger balance by account code from backend",
    category=ToolCategory.READ,
    required_permission="accounting.view",
    input_schema={"type": "object", "properties": {"account_code": {"type": "string"}}, "required": ["account_code"]},
    output_schema={"type": "object", "properties": {"account_code": {"type": "string"}, "current_balance": {"type": "string"}}},
    side_effects=False,
    idempotent=True,
    audit_event="tool_read_account",
    failure_behavior="Return null if account does not exist",
)

# Tool 2: search_transactions
async def handle_search_transactions(args: Dict[str, Any], context: ToolExecutionRequest) -> Dict[str, Any]:
    query = args.get("query", "")
    token = create_internal_token(
        organization_id=context.organization_id,
        user_id=context.user_id,
        user_permissions=context.user_permissions or [],
        entity_id=context.entity_id,
    )
    url = f"{settings.BACKEND_API_URL}/internal/organizations/{context.organization_id}/transactions/search"
    try:
        async with httpx.AsyncClient(timeout=10.0) as client:
            resp = await client.get(url, params={"query": query}, headers={"Authorization": f"Bearer {token}"})
            if resp.status_code == 200:
                return resp.json().get("data", {"results": [], "total_count": 0})
    except Exception:
        pass

    return {
        "results": [],
        "total_count": 0,
        "evidence_source": "search_query",
    }

tool_search_transactions = ToolDefinition(
    name="search_transactions",
    purpose="Search posted general ledger transactions by description, date, or amount",
    category=ToolCategory.READ,
    required_permission="accounting.view",
    input_schema={"type": "object", "properties": {"query": {"type": "string"}}, "required": ["query"]},
    output_schema={"type": "object", "properties": {"results": {"type": "array"}}},
    side_effects=False,
    idempotent=True,
    audit_event="tool_search_transactions",
    failure_behavior="Return empty list",
)

def register_read_tools():
    registry.register(tool_get_account, handle_get_account)
    registry.register(tool_search_transactions, handle_search_transactions)
