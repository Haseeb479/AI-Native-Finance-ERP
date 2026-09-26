from typing import Type, TypeVar
from decimal import Decimal
from apps.ai.src.adapters.base import BaseLLMAdapter, T
from apps.ai.src.schemas.classification import (
    TransactionClassificationResponse,
    AccountSuggestion,
)
from apps.ai.src.schemas.extraction import (
    InvoiceExtractionResponse,
    InvoiceLineItem,
)
from apps.ai.src.schemas.qa import (
    EvidenceCitation,
    FinancialQAResponse,
    JournalDraftResponse,
    JournalDraftLine,
    ReportExplanationResponse,
    VarianceDriver,
)

class MockLLMAdapter(BaseLLMAdapter):
    """
    Offline/Deterministic mock adapter for test suites and sandbox environments.
    """
    def provider_name(self) -> str:
        return "mock"

    async def generate_structured(
        self,
        prompt: str,
        system_instruction: str,
        response_model: Type[T],
        temperature: float = 0.1,
    ) -> T:
        # Provide deterministic mock data matching schemas
        if response_model == TransactionClassificationResponse:
            return TransactionClassificationResponse(
                suggested_account=AccountSuggestion(
                    account_code="6010",
                    account_name="Office Software & Subscriptions",
                    confidence=0.96,
                    rationale="Matches known recurring IT subscription pattern",
                    tax_category="sales_tax_exempt",
                ),
                alternative_suggestions=[
                    AccountSuggestion(
                        account_code="6020",
                        account_name="General Office Expenses",
                        confidence=0.72,
                        rationale="General administrative expense fallback",
                    )
                ],
                requires_human_review=False,
            ) # type: ignore
        
        if response_model == InvoiceExtractionResponse:
            return InvoiceExtractionResponse(
                vendor_name="SysNet Solutions (Pvt) Ltd",
                vendor_ntn="7849201-4",
                vendor_strn="3277876123456",
                invoice_number="INV-2026-089",
                currency="PKR",
                subtotal=Decimal("100000.00"),
                sales_tax_amount=Decimal("18000.00"),
                total_amount=Decimal("118000.00"),
                line_items=[
                    InvoiceLineItem(
                        description="Cloud Hosting & Infrastructure Maintenance",
                        quantity=Decimal("1.00"),
                        unit_price=Decimal("100000.00"),
                        tax_rate=Decimal("18.00"),
                        line_total=Decimal("118000.00"),
                    )
                ],
                extraction_confidence=0.94,
                flagged_for_review=False,
            ) # type: ignore

        if response_model == FinancialQAResponse:
            return FinancialQAResponse(
                answer="You currently have 16 pending invoices totaling PKR 3,240,000, and 3 journal entries awaiting manager approval.",
                key_metrics={
                    "pending_invoices_count": "16",
                    "pending_invoices_amount": "PKR 3,240,000",
                    "unreconciled_transactions": "24",
                },
                evidence=[
                    EvidenceCitation(
                        source_type="subledger",
                        record_id="subledger_invoices",
                        metric_or_code="open_invoices",
                        period_or_date="current",
                        stated_value="16",
                        verified=True,
                    ),
                    EvidenceCitation(
                        source_type="general_ledger",
                        record_id="cash_asset_account",
                        metric_or_code="cash_balance",
                        period_or_date="current",
                        stated_value="PKR 12,500,000",
                        verified=True,
                    ),
                ],
                suggested_actions=[
                    "Send payment reminders for invoices overdue > 30 days",
                    "Review pending journal draft #JE-2025-00042",
                ],
                confidence=0.95,
                groundedness_score=1.0,
                flagged_for_review=False,
            ) # type: ignore

        if response_model == JournalDraftResponse:
            amount = Decimal("50000.00")
            return JournalDraftResponse(
                description="Prepaid Office Rent Allocation",
                lines=[
                    JournalDraftLine(
                        account_code="6020",
                        account_name="Office Rent Expense",
                        debit=amount,
                        credit=Decimal("0.00"),
                        description="Monthly commercial office rent allocation",
                    ),
                    JournalDraftLine(
                        account_code="1060",
                        account_name="Prepayments and Advances",
                        debit=Decimal("0.00"),
                        credit=amount,
                        description="Reduction of security deposit / rent advance",
                    ),
                ],
                total_debit=amount,
                total_credit=amount,
                is_balanced=True,
                explanation="Recognizes monthly commercial office rent by debiting Rent Expense (6020) and crediting Prepayments (1060). Invariant Debit == Credit holds.",
            ) # type: ignore

        if response_model == ReportExplanationResponse:
            return ReportExplanationResponse(
                executive_summary="Net Profit for Q1 stands at PKR 350,000, driven by a 24% increase in consulting service revenue against stable operating expenses.",
                key_drivers=[
                    VarianceDriver(
                        account_or_category="Sales Revenue - Local",
                        movement_description="Increased by PKR 150,000 (+42%) due to enterprise client onboarding.",
                        impact_level="high",
                    ),
                    VarianceDriver(
                        account_or_category="Software & Cloud Subscriptions",
                        movement_description="Reduced by PKR 25,000 following server optimization.",
                        impact_level="medium",
                    ),
                ],
                evidence=[
                    EvidenceCitation(
                        source_type="report",
                        record_id="pnl_q1",
                        metric_or_code="revenue",
                        period_or_date="Q1 FY 2025-2026",
                        stated_value="PKR 500,000",
                        verified=True,
                    ),
                    EvidenceCitation(
                        source_type="report",
                        record_id="pnl_q1",
                        metric_or_code="gross_profit",
                        period_or_date="Q1 FY 2025-2026",
                        stated_value="PKR 350,000",
                        verified=True,
                    ),
                ],
                risk_flags=[
                    "Outstanding AR aging shows 3 invoices overdue beyond 60 days.",
                ],
                recommendations=[
                    "Initiate follow-ups on Accounts Receivable to protect operating cash flow.",
                    "Review quarterly estimated tax withholding prior to filing deadline.",
                ],
            ) # type: ignore

        raise NotImplementedError(f"Mock response not implemented for {response_model}")
