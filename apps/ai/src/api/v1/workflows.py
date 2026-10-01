from fastapi import APIRouter
from apps.ai.src.schemas.workflows import (
    MonthEndCloseWorkflowRequest,
    MonthEndCloseWorkflowResponse,
    UnreconciledTransactionsWorkflowRequest,
    UnreconciledTransactionsWorkflowResponse,
    MarginAnalysisWorkflowRequest,
    MarginAnalysisWorkflowResponse,
    InvoiceApprovalQueueWorkflowRequest,
    InvoiceApprovalQueueWorkflowResponse,
    DraftReconciliationMatchesWorkflowRequest,
    DraftReconciliationMatchesWorkflowResponse,
    MissingVendorDocumentsWorkflowRequest,
    MissingVendorDocumentsWorkflowResponse,
    ArCollectionsQueueWorkflowRequest,
    ArCollectionsQueueWorkflowResponse,
)
from apps.ai.src.adapters.factory import get_llm_adapter

router = APIRouter(prefix="/copilot/workflows")

CLOSE_SYSTEM_PROMPT = """
You are an expert AI Financial Controller preparing a period close.
Evaluate trial balance balance, open exceptions, depreciation routines, and draft journals.
Produce an objective readiness assessment score (0-100), list blocking items, and provide next steps.
"""

UNRECONCILED_SYSTEM_PROMPT = """
You are a senior treasury accountant analyzing unreconciled bank transactions.
Group and categorize transactions, identify suspected duplicates or timing discrepancies, and suggest concrete resolution steps.
"""

MARGIN_SYSTEM_PROMPT = """
You are a FP&A Director analyzing gross margin variance.
Decompose changes between current and prior periods into volume, price, and cost drivers.
Provide actionable executive commentary.
"""

INVOICE_APPROVAL_SYSTEM_PROMPT = """
You are an AI Accounts Payable auditor.
Score pending vendor bills for 3-way matching, duplicate payment risk, and policy compliance.
Rank approval priority into URGENT, NORMAL, and LOW with clear rationales.
"""

RECONCILIATION_MATCH_SYSTEM_PROMPT = """
You are a bank reconciliation engine.
Evaluate candidate match pairs between bank statements and ledger records.
Calculate confidence scores (0.0 to 1.0) and explain match rules (e.g., exact amount + date proximity).
"""

MISSING_DOCS_SYSTEM_PROMPT = """
You are an audit and tax compliance specialist.
Identify undocumented expenses and vendor bills, highlight tax withholding and deductibility risks, and suggest vendor follow-up actions.
"""

AR_COLLECTIONS_SYSTEM_PROMPT = """
You are an AI Credit & Collections Manager.
Analyze overdue accounts receivable, categorize customers into risk tiers (HIGH, MEDIUM, LOW), and prepare customized dunning notices and recovery actions.
"""

@router.post("/prepare-close", response_model=MonthEndCloseWorkflowResponse)
async def prepare_month_end_close(request: MonthEndCloseWorkflowRequest):
    prompt = (
        f"Period: {request.period_name} (ID: {request.period_id})\n"
        f"Trial Balance Balanced: {request.trial_balance_balanced}\n"
        f"Draft Journals Count: {request.draft_journals_count}\n"
        f"Unreconciled Bank Transactions: {request.unreconciled_bank_count}\n"
        f"Depreciation Routine Run: {request.depreciation_run}\n"
        f"Accruals Posted: {request.accruals_posted}\n"
        f"Open Financial Exceptions: {request.open_exceptions_count}\n"
    )
    adapter = get_llm_adapter()
    return await adapter.generate_structured(
        prompt=prompt,
        system_instruction=CLOSE_SYSTEM_PROMPT,
        response_model=MonthEndCloseWorkflowResponse,
    )

@router.post("/unreconciled-transactions", response_model=UnreconciledTransactionsWorkflowResponse)
async def find_unreconciled_transactions(request: UnreconciledTransactionsWorkflowRequest):
    prompt = (
        f"Organization: {request.organization_id}\n"
        f"Bank Account: {request.bank_account_id or 'All Accounts'}\n"
        f"Unreconciled Records ({len(request.unreconciled_items)}): {request.unreconciled_items}\n"
    )
    adapter = get_llm_adapter()
    return await adapter.generate_structured(
        prompt=prompt,
        system_instruction=UNRECONCILED_SYSTEM_PROMPT,
        response_model=UnreconciledTransactionsWorkflowResponse,
    )

@router.post("/margin-analysis", response_model=MarginAnalysisWorkflowResponse)
async def explain_margin_changes(request: MarginAnalysisWorkflowRequest):
    prompt = (
        f"Current Period ({request.current_period}): Revenue={request.current_revenue}, COGS={request.current_cogs}, Margin={request.current_gross_margin_pct}%\n"
        f"Prior Period ({request.prior_period}): Revenue={request.prior_revenue}, COGS={request.prior_cogs}, Margin={request.prior_gross_margin_pct}%\n"
        f"Operating Expenses Change: {request.operating_expenses_change_pct}%\n"
    )
    adapter = get_llm_adapter()
    return await adapter.generate_structured(
        prompt=prompt,
        system_instruction=MARGIN_SYSTEM_PROMPT,
        response_model=MarginAnalysisWorkflowResponse,
    )

@router.post("/invoice-approval-queue", response_model=InvoiceApprovalQueueWorkflowResponse)
async def prepare_invoice_approval_queue(request: InvoiceApprovalQueueWorkflowRequest):
    prompt = (
        f"Organization: {request.organization_id}\n"
        f"Pending Bills for Review ({len(request.pending_bills)}): {request.pending_bills}\n"
    )
    adapter = get_llm_adapter()
    return await adapter.generate_structured(
        prompt=prompt,
        system_instruction=INVOICE_APPROVAL_SYSTEM_PROMPT,
        response_model=InvoiceApprovalQueueWorkflowResponse,
    )

@router.post("/draft-reconciliation-matches", response_model=DraftReconciliationMatchesWorkflowResponse)
async def draft_reconciliation_matches(request: DraftReconciliationMatchesWorkflowRequest):
    prompt = (
        f"Bank Account: {request.bank_account_id}\n"
        f"Bank Transactions: {request.bank_transactions}\n"
        f"Ledger Entries: {request.candidate_ledger_entries}\n"
    )
    adapter = get_llm_adapter()
    return await adapter.generate_structured(
        prompt=prompt,
        system_instruction=RECONCILIATION_MATCH_SYSTEM_PROMPT,
        response_model=DraftReconciliationMatchesWorkflowResponse,
    )

@router.post("/missing-vendor-documents", response_model=MissingVendorDocumentsWorkflowResponse)
async def find_missing_vendor_documents(request: MissingVendorDocumentsWorkflowRequest):
    prompt = (
        f"Organization: {request.organization_id}\n"
        f"Bills under audit ({len(request.audit_bills)}): {request.audit_bills}\n"
    )
    adapter = get_llm_adapter()
    return await adapter.generate_structured(
        prompt=prompt,
        system_instruction=MISSING_DOCS_SYSTEM_PROMPT,
        response_model=MissingVendorDocumentsWorkflowResponse,
    )

@router.post("/ar-collections-queue", response_model=ArCollectionsQueueWorkflowResponse)
async def prepare_ar_collections_queue(request: ArCollectionsQueueWorkflowRequest):
    prompt = (
        f"Organization: {request.organization_id}\n"
        f"Overdue Invoices ({len(request.overdue_invoices)}): {request.overdue_invoices}\n"
    )
    adapter = get_llm_adapter()
    return await adapter.generate_structured(
        prompt=prompt,
        system_instruction=AR_COLLECTIONS_SYSTEM_PROMPT,
        response_model=ArCollectionsQueueWorkflowResponse,
    )
