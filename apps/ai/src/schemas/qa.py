from pydantic import BaseModel, Field
from typing import Optional, List, Dict, Any
from decimal import Decimal

# ─────────────────────────────────────────────────────────────
# 1. Financial Q&A
# ─────────────────────────────────────────────────────────────

class FinancialQARequest(BaseModel):
    query: str = Field(..., description="User financial question")
    organization_id: str
    currency: str = Field(default="PKR", description="Reporting currency")
    financial_context: Optional[Dict[str, Any]] = Field(
        default_factory=dict,
        description="Current organization metrics (cash, revenue, pending items)",
    )

class EvidenceCitation(BaseModel):
    source_type: str = Field(..., description="e.g. general_ledger, account_balance, subledger, report")
    record_id: Optional[str] = Field(None, description="Identifier of source record if applicable")
    metric_or_code: str = Field(..., description="Metric key or account code cited")
    period_or_date: Optional[str] = Field(None, description="Period or date of the cited evidence")
    stated_value: str = Field(..., description="Value asserted or extracted")
    verified: bool = Field(True, description="Whether this evidence was verified against server context")

class FinancialQAResponse(BaseModel):
    answer: str
    key_metrics: Dict[str, str] = Field(default_factory=dict)
    evidence: List[EvidenceCitation] = Field(default_factory=list, description="Structured evidence citations backing statements")
    suggested_actions: List[str] = Field(default_factory=list)
    confidence: float = Field(..., ge=0.0, le=1.0)
    groundedness_score: float = Field(default=1.0, ge=0.0, le=1.0, description="Faithfulness to server-authoritative context")
    flagged_for_review: bool = False

# ─────────────────────────────────────────────────────────────
# 2. Journal Draft Proposition
# ─────────────────────────────────────────────────────────────

class JournalDraftLine(BaseModel):
    account_code: str
    account_name: str
    debit: Decimal = Field(default=Decimal("0.00"))
    credit: Decimal = Field(default=Decimal("0.00"))
    description: Optional[str] = None

class JournalDraftRequest(BaseModel):
    instruction: str = Field(..., description="Description of the business event")
    amount: Optional[Decimal] = None
    currency: str = Field(default="PKR")
    organization_id: str

class JournalDraftResponse(BaseModel):
    description: str
    lines: List[JournalDraftLine]
    total_debit: Decimal
    total_credit: Decimal
    is_balanced: bool
    explanation: str

# ─────────────────────────────────────────────────────────────
# 3. Report Explanation / Variance Analysis
# ─────────────────────────────────────────────────────────────

class ReportExplanationRequest(BaseModel):
    report_type: str = Field(..., description="pnl, balance_sheet, or trial_balance")
    period_label: str
    report_data: Dict[str, Any]
    organization_id: str

class VarianceDriver(BaseModel):
    account_or_category: str
    movement_description: str
    impact_level: str = "medium"  # low, medium, high

class ReportExplanationResponse(BaseModel):
    executive_summary: str
    key_drivers: List[VarianceDriver] = Field(default_factory=list)
    evidence: List[EvidenceCitation] = Field(default_factory=list, description="Authoritative metrics cited in the variance explanation")
    risk_flags: List[str] = Field(default_factory=list)
    recommendations: List[str] = Field(default_factory=list)
