from pydantic import BaseModel, Field
from typing import Optional, List
from decimal import Decimal

class TransactionClassificationRequest(BaseModel):
    description: str = Field(..., description="Description from bank feed or receipt")
    amount: Decimal = Field(..., description="Monetary transaction amount in PKR or foreign currency")
    currency: str = Field(default="PKR", description="ISO 4217 Currency code")
    counterparty: Optional[str] = Field(None, description="Vendor or customer name if known")
    organization_id: str

class AccountSuggestion(BaseModel):
    account_code: str
    account_name: str
    confidence: float = Field(..., ge=0.0, le=1.0)
    rationale: str
    tax_category: Optional[str] = None

class TransactionClassificationResponse(BaseModel):
    suggested_account: AccountSuggestion
    alternative_suggestions: List[AccountSuggestion] = Field(default_factory=list)
    requires_human_review: bool = False
    review_reason: Optional[str] = None
