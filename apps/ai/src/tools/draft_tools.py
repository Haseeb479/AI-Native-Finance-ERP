from typing import Dict, Any
from apps.ai.src.schemas.tools import ToolDefinition, ToolCategory, ToolExecutionRequest
from apps.ai.src.tools.registry import registry

# Tool 3: draft_journal
async def handle_draft_journal(args: Dict[str, Any], context: ToolExecutionRequest) -> Dict[str, Any]:
    # Ensure debits == credits in the draft proposition
    lines = args.get("lines", [])
    total_debit = sum(float(l.get("debit", 0)) for l in lines)
    total_credit = sum(float(l.get("credit", 0)) for l in lines)

    if round(total_debit, 2) != round(total_credit, 2):
        raise ValueError(
            f"Cannot create unbalanced journal draft. Total Debit ({total_debit}) != Total Credit ({total_credit})."
        )

    return {
        "draft_id": "draft-jr-902",
        "description": args.get("description", "AI Prototyped Journal Draft"),
        "total_debit": f"{total_debit:.2f}",
        "total_credit": f"{total_credit:.2f}",
        "status": "draft_pending_review",
        "requires_human_approval": True,
        "message": "Journal draft created successfully. Must be approved and posted via the posting engine.",
    }

tool_draft_journal = ToolDefinition(
    name="draft_journal",
    purpose="Prepare a balanced double-entry journal draft for accountant review and approval",
    category=ToolCategory.DRAFT,
    required_permission="accounting.journal.create",
    input_schema={
        "type": "object",
        "properties": {
            "description": {"type": "string"},
            "lines": {"type": "array", "items": {"type": "object"}},
        },
        "required": ["description", "lines"],
    },
    output_schema={"type": "object", "properties": {"draft_id": {"type": "string"}, "status": {"type": "string"}}},
    side_effects=True,
    idempotent=False,
    audit_event="tool_draft_journal_created",
    failure_behavior="Fail without altering any state",
)

def register_draft_tools():
    registry.register(tool_draft_journal, handle_draft_journal)
