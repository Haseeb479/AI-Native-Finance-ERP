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

        raise NotImplementedError(f"Mock response not implemented for {response_model}")
