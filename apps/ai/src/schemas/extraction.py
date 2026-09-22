from pydantic import BaseModel, Field
from typing import Optional, List
from decimal import Decimal
from datetime import date

class InvoiceLineItem(BaseModel):
    description: str
    quantity: Decimal
    unit_price: Decimal
    tax_rate: Decimal = Decimal("0.00")
    line_total: Decimal

class InvoiceExtractionResponse(BaseModel):
    vendor_name: Optional[str] = None
    vendor_ntn: Optional[str] = Field(None, description="National Tax Number (Pakistan)")
    vendor_strn: Optional[str] = Field(None, description="Sales Tax Registration Number (Pakistan)")
    invoice_number: Optional[str] = None
    invoice_date: Optional[date] = None
    due_date: Optional[date] = None
    currency: str = "PKR"
    subtotal: Decimal
    sales_tax_amount: Decimal = Decimal("0.00")
    total_amount: Decimal
    line_items: List[InvoiceLineItem] = Field(default_factory=list)
    extraction_confidence: float = Field(..., ge=0.0, le=1.0)
    flagged_for_review: bool = False
    review_notes: Optional[str] = None
