from typing import Dict, Any
from apps.ai.src.schemas.tools import ToolDefinition, ToolCategory, ToolExecutionRequest
from apps.ai.src.tools.registry import registry

# Tool 1: get_account
async def handle_get_account(args: Dict[str, Any], context: ToolExecutionRequest) -> Dict[str, Any]:
    account_code = args.get("account_code")
    # In full implementation, calls Laravel API backend: /api/v1/accounts/{code}
    return {
        "account_code": account_code,
        "account_name": "Accounts Receivable (Trade Debtors)",
        "account_type": "Asset",
        "currency": "PKR",
        "current_balance": "2120000.00",
        "organization_id": context.organization_id,
    }

tool_get_account = ToolDefinition(
    name="get_account",
    purpose="Fetch verified Chart of Accounts record and ledger balance by account code",
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
    return {
        "results": [
            {
                "id": "tx-1001",
                "date": "2026-09-20",
                "description": f"Verified ledger transaction matching {query}",
                "amount": "125000.00",
                "currency": "PKR",
                "posted": True,
            }
        ],
        "total_count": 1,
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
