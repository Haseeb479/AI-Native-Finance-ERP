from pydantic import BaseModel, Field
from typing import Optional, List, Dict, Any
from decimal import Decimal
from apps.ai.src.schemas.qa import EvidenceCitation

# 1. Month-End Close Preparation
class MonthEndCloseWorkflowRequest(BaseModel):
    organization_id: str
    period_id: str
    period_name: str
    trial_balance_balanced: bool
    draft_journals_count: int
    unreconciled_bank_count: int
    depreciation_run: bool
    accruals_posted: bool
    open_exceptions_count: int = 0

class MonthEndCloseWorkflowResponse(BaseModel):
    readiness_status: str = Field(..., description="READY, BLOCKED, or IN_PROGRESS")
    readiness_score_pct: int = Field(..., ge=0, le=100)
    executive_assessment: str
    blocking_items: List[str] = Field(default_factory=list)
    action_checklist: List[str] = Field(default_factory=list)
    evidence: List[EvidenceCitation] = Field(default_factory=list)

# 2. Find Unreconciled Transactions
class UnreconciledTransactionsWorkflowRequest(BaseModel):
    organization_id: str
    bank_account_id: Optional[str] = None
    unreconciled_items: List[Dict[str, Any]] = Field(default_factory=list)

class UnreconciledItemAnalysis(BaseModel):
    transaction_id: str
    date: str
    amount: str
    description: str
    likely_category: str
    suggested_match_target: Optional[str] = None
    risk_level: str = "medium"

class UnreconciledTransactionsWorkflowResponse(BaseModel):
    total_unreconciled_amount: str
    total_count: int
    summary: str
    analyzed_items: List[UnreconciledItemAnalysis] = Field(default_factory=list)
    recommended_resolutions: List[str] = Field(default_factory=list)

# 3. Explain Margin Changes
class MarginAnalysisWorkflowRequest(BaseModel):
    organization_id: str
    current_period: str
    prior_period: str
    current_revenue: Decimal
    current_cogs: Decimal
    current_gross_margin_pct: Decimal
    prior_revenue: Decimal
    prior_cogs: Decimal
    prior_gross_margin_pct: Decimal
    operating_expenses_change_pct: Optional[Decimal] = None

class MarginDriver(BaseModel):
    factor_name: str
    impact_bps: int
    direction: str  # positive, negative
    explanation: str

class MarginAnalysisWorkflowResponse(BaseModel):
    gross_margin_delta_bps: int
    executive_summary: str
    primary_drivers: List[MarginDriver] = Field(default_factory=list)
    operational_risks: List[str] = Field(default_factory=list)
    pricing_or_cost_recommendations: List[str] = Field(default_factory=list)

# 4. Prepare Invoice Approval Queue
class InvoiceApprovalQueueWorkflowRequest(BaseModel):
    organization_id: str
    pending_bills: List[Dict[str, Any]] = Field(default_factory=list)

class ScoredApprovalItem(BaseModel):
    bill_id: str
    vendor_name: str
    amount: str
    priority_level: str  # URGENT, NORMAL, LOW
    three_way_match_status: str  # MATCHED, PARTIAL, UNMATCHED
    duplicate_risk: bool = False
    approval_recommendation: str  # APPROVE, REJECT, REQUEST_REVIEW
    rationale: str

class InvoiceApprovalQueueWorkflowResponse(BaseModel):
    total_pending_count: int
    total_pending_amount: str
    approval_queue: List[ScoredApprovalItem] = Field(default_factory=list)
    policy_alerts: List[str] = Field(default_factory=list)

# 5. Draft Reconciliation Matches
class CandidateMatchPair(BaseModel):
    bank_transaction_id: str
    matched_record_id: str
    matched_record_type: str  # journal_entry, invoice_payment, purchase_bill
    confidence_score: float = Field(..., ge=0.0, le=1.0)
    match_rule: str
    amount: str
    explanation: str

class DraftReconciliationMatchesWorkflowRequest(BaseModel):
    organization_id: str
    bank_account_id: str
    bank_transactions: List[Dict[str, Any]] = Field(default_factory=list)
    candidate_ledger_entries: List[Dict[str, Any]] = Field(default_factory=list)

class DraftReconciliationMatchesWorkflowResponse(BaseModel):
    proposed_matches: List[CandidateMatchPair] = Field(default_factory=list)
    unmatched_count: int
    matching_rate_pct: int

# 6. Find Missing Vendor Documents
class MissingVendorDocumentsWorkflowRequest(BaseModel):
    organization_id: str
    audit_bills: List[Dict[str, Any]] = Field(default_factory=list)

class MissingDocRisk(BaseModel):
    bill_id: str
    bill_number: str
    vendor_name: str
    amount: str
    date: str
    tax_withholding_risk: bool
    recommended_action: str

class MissingVendorDocumentsWorkflowResponse(BaseModel):
    missing_docs_count: int
    total_undocumented_amount: str
    risk_summary: str
    flagged_records: List[MissingDocRisk] = Field(default_factory=list)

# 7. Prepare AR Collections Queue
class ArCollectionsQueueWorkflowRequest(BaseModel):
    organization_id: str
    overdue_invoices: List[Dict[str, Any]] = Field(default_factory=list)

class CollectionsQueueItem(BaseModel):
    invoice_id: str
    customer_name: str
    days_overdue: int
    amount_due: str
    risk_tier: str  # HIGH, MEDIUM, LOW
    suggested_dunning_action: str
    draft_email_snippet: str

class ArCollectionsQueueWorkflowResponse(BaseModel):
    total_overdue_amount: str
    total_customers_overdue: int
    high_priority_count: int
    action_queue: List[CollectionsQueueItem] = Field(default_factory=list)
