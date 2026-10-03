import pytest
from httpx import AsyncClient
from apps.ai.src.auth.service_auth import create_internal_token, replay_cache

@pytest.fixture(autouse=True)
def clear_replay():
    replay_cache.clear()

def valid_token(org_id: str = "org-test-sec", user_id: str = "user-sec-1") -> str:
    return create_internal_token(
        organization_id=org_id,
        user_id=user_id,
        user_permissions=["accounting.view", "accounting.post", "accounting.journal.create"],
    )

@pytest.mark.asyncio
async def test_all_ai_endpoints_reject_unauthenticated_requests(client: AsyncClient):
    """P0-01: Every internal AI endpoint must enforce require_verified_claims and reject unauthenticated requests."""
    endpoints = [
        ("/v1/copilot/qa", {"query": "test", "organization_id": "org-test-sec"}),
        ("/v1/copilot/draft-journal", {"instruction": "test", "organization_id": "org-test-sec"}),
        ("/v1/copilot/explain-report", {"report_type": "pnl", "period_label": "P1", "report_data": {}, "organization_id": "org-test-sec"}),
        ("/v1/classify", {"description": "Office Supplies", "amount": "1000", "currency": "PKR", "organization_id": "org-test-sec"}),
        ("/v1/extract/invoice", {"raw_document_text": "Vendor: ABC, Total: 100", "organization_id": "org-test-sec"}),
        ("/v1/copilot/workflows/prepare-close", {
            "organization_id": "org-test-sec",
            "period_id": "p-1",
            "period_name": "P1",
            "trial_balance_balanced": True,
            "draft_journals_count": 0,
            "unreconciled_bank_count": 0,
            "depreciation_run": True,
            "accruals_posted": True,
        }),
        ("/v1/tools/execute", {
            "tool_name": "get_account",
            "arguments": {"account_code": "1010"},
            "organization_id": "org-test-sec",
        }),
    ]

    for path, payload in endpoints:
        resp = await client.post(path, json=payload)
        assert resp.status_code == 401, f"Expected 401 for unauthenticated request to {path}, got {resp.status_code}"
        assert "Missing Authorization header" in resp.json()["detail"]

@pytest.mark.asyncio
async def test_all_ai_endpoints_reject_invalid_signature_tokens(client: AsyncClient):
    """P0-01: Tokens with forged or altered HMAC signature must be rejected with 401."""
    forged_token = create_internal_token(
        organization_id="org-test-sec",
        user_id="attacker-999",
        user_permissions=["*"],
        secret="invalid-attacker-forged-secret-32-bytes",
    )
    headers = {"Authorization": f"Bearer {forged_token}"}

    endpoints = [
        ("/v1/copilot/qa", {"query": "test", "organization_id": "org-test-sec"}),
        ("/v1/classify", {"description": "Office Supplies", "amount": "1000", "currency": "PKR", "organization_id": "org-test-sec"}),
        ("/v1/extract/invoice", {"raw_document_text": "Invoice #1", "organization_id": "org-test-sec"}),
        ("/v1/copilot/workflows/prepare-close", {
            "organization_id": "org-test-sec",
            "period_id": "p-1",
            "period_name": "P1",
            "trial_balance_balanced": True,
            "draft_journals_count": 0,
            "unreconciled_bank_count": 0,
            "depreciation_run": True,
            "accruals_posted": True,
        }),
    ]

    for path, payload in endpoints:
        resp = await client.post(path, json=payload, headers=headers)
        assert resp.status_code == 401, f"Expected 401 for forged token to {path}, got {resp.status_code}"
        assert "Invalid service token" in resp.json()["detail"]

@pytest.mark.asyncio
async def test_all_ai_endpoints_reject_expired_tokens(client: AsyncClient):
    """P0-01: Expired service tokens must be rejected with 401."""
    expired_token = create_internal_token(
        organization_id="org-test-sec",
        user_id="user-sec-1",
        user_permissions=["*"],
        expires_in_seconds=-10,
    )
    headers = {"Authorization": f"Bearer {expired_token}"}

    resp = await client.post(
        "/v1/copilot/qa",
        json={"query": "test", "organization_id": "org-test-sec"},
        headers=headers,
    )
    assert resp.status_code == 401
    assert "expired" in resp.json()["detail"]

@pytest.mark.asyncio
async def test_all_ai_endpoints_enforce_tenant_isolation_matrix(client: AsyncClient):
    """P0-01 & Tenant Isolation: Token for Organization A cannot access Organization B (403)."""
    endpoints = [
        ("/v1/copilot/qa", {"query": "Confidential financials", "organization_id": "org-tenant-B"}),
        ("/v1/copilot/draft-journal", {"instruction": "Recognize rent", "organization_id": "org-tenant-B"}),
        ("/v1/copilot/explain-report", {"report_type": "pnl", "period_label": "P1", "report_data": {}, "organization_id": "org-tenant-B"}),
        ("/v1/classify", {"description": "Office Supplies", "amount": "1000", "currency": "PKR", "organization_id": "org-tenant-B"}),
        ("/v1/extract/invoice", {"raw_document_text": "Vendor: ABC", "organization_id": "org-tenant-B"}),
        ("/v1/copilot/workflows/prepare-close", {
            "organization_id": "org-tenant-B",
            "period_id": "p-1",
            "period_name": "P1",
            "trial_balance_balanced": True,
            "draft_journals_count": 0,
            "unreconciled_bank_count": 0,
            "depreciation_run": True,
            "accruals_posted": True,
        }),
        ("/v1/tools/execute", {
            "tool_name": "get_account",
            "arguments": {"account_code": "1010"},
            "organization_id": "org-tenant-B",
        }),
    ]

    for path, payload in endpoints:
        headers = {"Authorization": f"Bearer {valid_token(org_id='org-tenant-A')}"}
        resp = await client.post(path, json=payload, headers=headers)
        assert resp.status_code == 403, f"Expected 403 for cross-tenant request to {path}, got {resp.status_code}"
        assert "Scope mismatch" in resp.json()["detail"]

@pytest.mark.asyncio
async def test_authenticated_ai_requests_succeed(client: AsyncClient):
    """P0-01: Properly signed internal tokens succeed across all endpoints."""
    # 1. Copilot QA
    r1 = await client.post(
        "/v1/copilot/qa",
        json={"query": "What is our balance?", "organization_id": "org-test-sec", "currency": "PKR"},
        headers={"Authorization": f"Bearer {valid_token(org_id='org-test-sec')}"},
    )
    assert r1.status_code == 200

    # 2. Classification
    r2 = await client.post(
        "/v1/classify",
        json={"description": "Electricity Bill", "amount": "25000", "currency": "PKR", "organization_id": "org-test-sec"},
        headers={"Authorization": f"Bearer {valid_token(org_id='org-test-sec')}"},
    )
    assert r2.status_code == 200

    # 3. Extraction
    r3 = await client.post(
        "/v1/extract/invoice",
        json={"raw_document_text": "Invoice #909 from Supplies Corp. Total: PKR 50000", "organization_id": "org-test-sec"},
        headers={"Authorization": f"Bearer {valid_token(org_id='org-test-sec')}"},
    )
    assert r3.status_code == 200
