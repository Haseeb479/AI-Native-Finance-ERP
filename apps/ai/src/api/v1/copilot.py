from fastapi import APIRouter, HTTPException
from apps.ai.src.schemas.qa import (
    FinancialQARequest,
    FinancialQAResponse,
    JournalDraftRequest,
    JournalDraftResponse,
    ReportExplanationRequest,
    ReportExplanationResponse,
)
from apps.ai.src.schemas.guardrails import sanitize_untrusted_document_text
from apps.ai.src.adapters.factory import get_llm_adapter

router = APIRouter(prefix="/copilot")

QA_SYSTEM_PROMPT = """
You are the AI Financial Controller and Copilot for AI-Native Finance ERP in Pakistan.
Answer natural language financial questions accurately using provided financial context.
Ground answers in real accounting data.
Never invent ledger transactions.
If asked to mutate or post financial records, inform the user that changes must be created as drafts and approved by authorized personnel.
"""

JOURNAL_SYSTEM_PROMPT = """
You are an expert Certified Public Accountant specializing in double-entry bookkeeping.
Draft balanced journal entries matching Pakistan SME Chart of Accounts codes.
Every draft MUST satisfy: Sum(Debit) == Sum(Credit).
Never post directly; output a structured draft proposal for accountant review.
"""

REPORT_SYSTEM_PROMPT = """
You are an executive financial analyst.
Analyze financial statements (P&L, Balance Sheet, Trial Balance) and generate clear executive summaries, identify top variance drivers, highlight operational risk flags, and provide actionable recommendations.
"""

@router.post("/qa", response_model=FinancialQAResponse)
async def ask_financial_qa(request: FinancialQARequest):
    # Neutralize prompt injection attempts
    cleaned_query, flagged = sanitize_untrusted_document_text(request.query)

    context_str = "\n".join(f"{k}: {v}" for k, v in request.financial_context.items()) if request.financial_context else "No active metrics provided."
    prompt = (
        f"Financial Question: {cleaned_query}\n"
        f"Reporting Currency: {request.currency}\n"
        f"Current Organization Financial Context:\n{context_str}\n"
    )

    adapter = get_llm_adapter()
    response = await adapter.generate_structured(
        prompt=prompt,
        system_instruction=QA_SYSTEM_PROMPT,
        response_model=FinancialQAResponse,
    )

    # P1-15: Server-side groundedness validation of evidence citations
    if response.evidence:
        context_keys = {str(k).lower(): str(v).lower() for k, v in (request.financial_context or {}).items()}
        verified_count = 0
        for citation in response.evidence:
            metric_key = citation.metric_or_code.lower()
            if metric_key in context_keys:
                citation.verified = True
                verified_count += 1
            else:
                # Check substring match in context values
                matched = any(citation.stated_value.lower() in v for v in context_keys.values())
                citation.verified = matched
                if matched:
                    verified_count += 1

        response.groundedness_score = round(verified_count / len(response.evidence), 2)
        if response.groundedness_score < 0.75:
            response.flagged_for_review = True

    if flagged:
        response.flagged_for_review = True
        response.groundedness_score = min(response.groundedness_score, 0.5)

    return response

@router.post("/draft-journal", response_model=JournalDraftResponse)
async def draft_journal(request: JournalDraftRequest):
    cleaned_instruction, _ = sanitize_untrusted_document_text(request.instruction)
    prompt = (
        f"Draft Journal Instruction: {cleaned_instruction}\n"
        f"Amount: {request.amount if request.amount else 'Extract from text'}\n"
        f"Currency: {request.currency}\n"
    )

    adapter = get_llm_adapter()
    draft = await adapter.generate_structured(
        prompt=prompt,
        system_instruction=JOURNAL_SYSTEM_PROMPT,
        response_model=JournalDraftResponse,
    )

    # Validate accounting invariant: ∑Debit == ∑Credit
    if round(float(draft.total_debit), 2) != round(float(draft.total_credit), 2):
        raise HTTPException(
            status_code=422,
            detail=f"Double-entry violation: Total Debit ({draft.total_debit}) != Total Credit ({draft.total_credit})"
        )

    return draft

@router.post("/explain-report", response_model=ReportExplanationResponse)
async def explain_report(request: ReportExplanationRequest):
    prompt = (
        f"Report Type: {request.report_type}\n"
        f"Period: {request.period_label}\n"
        f"Financial Statement Data: {request.report_data}\n"
    )

    adapter = get_llm_adapter()
    return await adapter.generate_structured(
        prompt=prompt,
        system_instruction=REPORT_SYSTEM_PROMPT,
        response_model=ReportExplanationResponse,
    )
