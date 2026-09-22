import re
from typing import Tuple

# Common prompt injection triggers embedded in invoices / documents
INJECTION_PATTERNS = [
    r"ignore\s+(all\s+)?(previous|prior)\s+instructions",
    r"override\s+(system|accounting)\s+rules",
    r"transfer\s+funds\s+to",
    r"drop\s+table",
    r"update\s+account_balances",
    r"delete\s+from",
    r"grant\s+all\s+privileges",
    r"admin\s+mode",
]

def sanitize_untrusted_document_text(text: str) -> Tuple[str, bool]:
    """
    Sanitizes text extracted from invoices/receipts/emails.
    Flags potential prompt injection attacks while neutralizing malicious directives.
    """
    flagged = False
    cleaned_text = text
    
    for pattern in INJECTION_PATTERNS:
        if re.search(pattern, cleaned_text, re.IGNORECASE):
            flagged = True
            # Neutralize the command in context
            cleaned_text = re.sub(pattern, "[UNTRUSTED_INSTRUCTION_STRIPPED]", cleaned_text, flags=re.IGNORECASE)
            
    return cleaned_text, flagged

def assert_no_direct_mutation(tool_name: str) -> None:
    """
    Asserts that AI is not invoking forbidden balance-mutating commands directly.
    """
    forbidden = ["set_balance", "update_ledger_balance", "direct_sql", "execute_sql"]
    if tool_name.lower() in forbidden:
        raise ValueError(
            f"Security Violation: AI cannot execute direct mutation tool '{tool_name}'. "
            "Financial mutations must pass through the deterministic posting engine."
        )
