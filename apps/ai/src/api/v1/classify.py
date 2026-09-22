from fastapi import APIRouter
from apps.ai.src.schemas.classification import (
    TransactionClassificationRequest,
    TransactionClassificationResponse,
)
from apps.ai.src.adapters.factory import get_llm_adapter

router = APIRouter(prefix="/classify")

SYSTEM_PROMPT = """
You are the AI Classification Specialist for AI-Native Finance ERP.
Classify financial transactions into Chart of Accounts codes based on accounting principles.
Output valid JSON adhering to the specified schema.
Never invent accounts. If uncertain, set requires_human_review=True with a clear rationale.
"""

@router.post("", response_model=TransactionClassificationResponse)
async def classify_transaction(request: TransactionClassificationRequest):
    adapter = get_llm_adapter()
    prompt = (
        f"Classify transaction:\n"
        f"Description: {request.description}\n"
        f"Amount: {request.amount} {request.currency}\n"
        f"Counterparty: {request.counterparty or 'Unknown'}"
    )

    return await adapter.generate_structured(
        prompt=prompt,
        system_instruction=SYSTEM_PROMPT,
        response_model=TransactionClassificationResponse,
    )
