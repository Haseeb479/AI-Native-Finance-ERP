import pytest
from fastapi.testclient import TestClient
from apps.ai.src.main import app
from apps.ai.src.auth.service_auth import create_internal_token, replay_cache

client = TestClient(app)

@pytest.fixture(autouse=True)
def clear_replay():
    replay_cache.clear()

def auth_headers(org_id: str = "org-test-123") -> dict:
    token = create_internal_token(
        organization_id=org_id,
        user_id="user-wf-1",
        user_permissions=["accounting.view", "accounting.post"],
    )
    return {"Authorization": f"Bearer {token}"}

def test_workflow_prepare_month_end_close():
    payload = {
        "organization_id": "org-test-123",
        "period_id": "period-2025-09",
        "period_name": "September 2025",
        "trial_balance_balanced": True,
        "draft_journals_count": 0,
        "unreconciled_bank_count": 0,
        "depreciation_run": True,
        "accruals_posted": True,
        "open_exceptions_count": 0,
    }
    response = client.post("/v1/copilot/workflows/prepare-close", json=payload, headers=auth_headers("org-test-123"))
    assert response.status_code == 200
    data = response.json()
    assert "readiness_status" in data
    assert "readiness_score_pct" in data
    assert "executive_assessment" in data

def test_workflow_unreconciled_transactions():
    payload = {
        "organization_id": "org-test-123",
        "bank_account_id": "bank-acc-01",
        "unreconciled_items": [
            {
                "id": "tx-1",
                "date": "2025-09-15",
                "amount": "15000.00",
                "description": "Vendor transfer pending match",
            }
        ],
    }
    response = client.post("/v1/copilot/workflows/unreconciled-transactions", json=payload, headers=auth_headers("org-test-123"))
    assert response.status_code == 200
    data = response.json()
    assert "total_unreconciled_amount" in data
    assert "summary" in data

def test_workflow_margin_analysis():
    payload = {
        "organization_id": "org-test-123",
        "current_period": "Q3 2025",
        "prior_period": "Q2 2025",
        "current_revenue": "100000.00",
        "current_cogs": "60000.00",
        "current_gross_margin_pct": "40.00",
        "prior_revenue": "80000.00",
        "prior_cogs": "52000.00",
        "prior_gross_margin_pct": "35.00",
        "operating_expenses_change_pct": "5.0",
    }
    response = client.post("/v1/copilot/workflows/margin-analysis", json=payload, headers=auth_headers("org-test-123"))
    assert response.status_code == 200
    data = response.json()
    assert "gross_margin_delta_bps" in data
    assert "executive_summary" in data

def test_workflow_invoice_approval_queue():
    payload = {
        "organization_id": "org-test-123",
        "pending_bills": [
            {
                "id": "bill-101",
                "vendor_name": "Atlas Supplies",
                "amount": "45000.00",
                "po_number": "PO-900",
            }
        ],
    }
    response = client.post("/v1/copilot/workflows/invoice-approval-queue", json=payload, headers=auth_headers("org-test-123"))
    assert response.status_code == 200
    data = response.json()
    assert "total_pending_count" in data
    assert "approval_queue" in data

def test_workflow_draft_reconciliation_matches():
    payload = {
        "organization_id": "org-test-123",
        "bank_account_id": "bank-acc-01",
        "bank_transactions": [{"id": "bt-1", "amount": "5000.00", "date": "2025-09-20"}],
        "candidate_ledger_entries": [{"id": "je-1", "amount": "5000.00", "date": "2025-09-20"}],
    }
    response = client.post("/v1/copilot/workflows/draft-reconciliation-matches", json=payload, headers=auth_headers("org-test-123"))
    assert response.status_code == 200
    data = response.json()
    assert "proposed_matches" in data
    assert "matching_rate_pct" in data

def test_workflow_missing_vendor_documents():
    payload = {
        "organization_id": "org-test-123",
        "audit_bills": [
            {
                "id": "bill-201",
                "bill_number": "INV-201",
                "vendor_name": "Premier Logistics",
                "amount": "80000.00",
                "date": "2025-09-10",
                "has_attachment": False,
            }
        ],
    }
    response = client.post("/v1/copilot/workflows/missing-vendor-documents", json=payload, headers=auth_headers("org-test-123"))
    assert response.status_code == 200
    data = response.json()
    assert "missing_docs_count" in data
    assert "risk_summary" in data

def test_workflow_ar_collections_queue():
    payload = {
        "organization_id": "org-test-123",
        "overdue_invoices": [
            {
                "id": "inv-501",
                "customer_name": "Pak Retailers Ltd",
                "days_overdue": 45,
                "amount_due": "120000.00",
            }
        ],
    }
    response = client.post("/v1/copilot/workflows/ar-collections-queue", json=payload, headers=auth_headers("org-test-123"))
    assert response.status_code == 200
    data = response.json()
    assert "total_overdue_amount" in data
    assert "action_queue" in data

def test_workflow_rejects_unauthenticated():
    """P0: All workflow endpoints must reject unauthenticated requests with 401."""
    payload = {"organization_id": "org-test-123", "period_id": "p-1", "period_name": "P1", "trial_balance_balanced": True, "draft_journals_count": 0, "unreconciled_bank_count": 0, "depreciation_run": True, "accruals_posted": True}
    response = client.post("/v1/copilot/workflows/prepare-close", json=payload)
    assert response.status_code == 401
    assert "Missing Authorization header" in response.json()["detail"]

def test_workflow_rejects_scope_mismatch_cross_tenant():
    """P0: Organization A token cannot trigger workflows targeting Organization B."""
    token = create_internal_token(organization_id="org-tenant-A", user_id="user-1", user_permissions=["*"])
    payload = {"organization_id": "org-tenant-B", "period_id": "p-1", "period_name": "P1", "trial_balance_balanced": True, "draft_journals_count": 0, "unreconciled_bank_count": 0, "depreciation_run": True, "accruals_posted": True}
    response = client.post("/v1/copilot/workflows/prepare-close", json=payload, headers={"Authorization": f"Bearer {token}"})
    assert response.status_code == 403
    assert "Scope mismatch" in response.json()["detail"]
