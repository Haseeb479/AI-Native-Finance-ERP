import pytest
from httpx import AsyncClient
from apps.ai.src.auth.service_auth import create_internal_token, replay_cache

@pytest.fixture(autouse=True)
def clear_replay():
    replay_cache.clear()

@pytest.mark.asyncio
async def test_list_tools(client: AsyncClient):
    response = await client.get("/v1/tools")
    assert response.status_code == 200
    tools = response.json()
    tool_names = [t["name"] for t in tools]
    assert "get_account" in tool_names
    assert "search_transactions" in tool_names
    assert "draft_journal" in tool_names

@pytest.mark.asyncio
async def test_direct_unauthenticated_execution_fails(client: AsyncClient):
    """P0-01: Direct unauthenticated tool execution must fail with 401."""
    payload = {
        "tool_name": "get_account",
        "arguments": {"account_code": "1010"},
        "organization_id": "org-pk-001",
    }
    response = await client.post("/v1/tools/execute", json=payload)
    assert response.status_code == 401
    assert "Missing Authorization header" in response.json()["detail"]

@pytest.mark.asyncio
async def test_expired_token_fails(client: AsyncClient):
    """P0-01: Expired signed token must fail with 401."""
    expired_token = create_internal_token(
        organization_id="org-pk-001",
        user_id="user-479",
        user_permissions=["accounting.view"],
        expires_in_seconds=-10,  # Expired in past
    )
    payload = {
        "tool_name": "get_account",
        "arguments": {"account_code": "1010"},
    }
    response = await client.post(
        "/v1/tools/execute",
        json=payload,
        headers={"Authorization": f"Bearer {expired_token}"},
    )
    assert response.status_code == 401
    assert "expired" in response.json()["detail"]

@pytest.mark.asyncio
async def test_invalid_signature_fails(client: AsyncClient):
    """P0-01: Token with bad signature must fail with 401."""
    bad_token = create_internal_token(
        organization_id="org-pk-001",
        user_id="user-479",
        user_permissions=["accounting.view"],
        secret="wrong-secret-key-attacker-32-bytes-long",
    )
    payload = {
        "tool_name": "get_account",
        "arguments": {"account_code": "1010"},
    }
    response = await client.post(
        "/v1/tools/execute",
        json=payload,
        headers={"Authorization": f"Bearer {bad_token}"},
    )
    assert response.status_code == 401
    assert "Invalid service token" in response.json()["detail"]

@pytest.mark.asyncio
async def test_replay_attack_rejected(client: AsyncClient):
    """P0-01: Replaying the exact same signed token must fail."""
    token = create_internal_token(
        organization_id="org-pk-001",
        user_id="user-479",
        user_permissions=["accounting.view"],
        nonce="unique-nonce-12345",
    )
    payload = {
        "tool_name": "get_account",
        "arguments": {"account_code": "1010"},
    }
    # First execution succeeds
    r1 = await client.post(
        "/v1/tools/execute",
        json=payload,
        headers={"Authorization": f"Bearer {token}"},
    )
    assert r1.status_code == 200

    # Replay execution with same token/nonce is rejected
    r2 = await client.post(
        "/v1/tools/execute",
        json=payload,
        headers={"Authorization": f"Bearer {token}"},
    )
    assert r2.status_code == 401
    assert "Replay attack detected" in r2.json()["detail"]

@pytest.mark.asyncio
async def test_forged_user_permissions_cannot_elevate_privileges(client: AsyncClient):
    """P0-01: Attacker sending wildcard permissions in JSON body is denied because permissions come from token claims."""
    # Token only grants view permission
    token = create_internal_token(
        organization_id="org-pk-001",
        user_id="user-479",
        user_permissions=["accounting.view"],
    )
    # Attacker tries to inject wildcard in request body
    payload = {
        "tool_name": "draft_journal",
        "arguments": {"description": "Privilege escalation attempt", "lines": []},
        "user_permissions": ["*"],  # Forged elevation
    }
    response = await client.post(
        "/v1/tools/execute",
        json=payload,
        headers={"Authorization": f"Bearer {token}"},
    )
    assert response.status_code == 200
    data = response.json()
    assert data["success"] is False
    assert "Permission denied" in data["error"]

@pytest.mark.asyncio
async def test_forged_scope_mismatch_rejected(client: AsyncClient):
    """P0-01: Attempting to target a different organization than signed token fails with 403."""
    token = create_internal_token(
        organization_id="org-pk-001",
        user_id="user-479",
        user_permissions=["accounting.view"],
    )
    payload = {
        "tool_name": "get_account",
        "arguments": {"account_code": "1010"},
        "organization_id": "org-victim-999",  # Cross-tenant attack
    }
    response = await client.post(
        "/v1/tools/execute",
        json=payload,
        headers={"Authorization": f"Bearer {token}"},
    )
    assert response.status_code == 403
    assert "Scope mismatch" in response.json()["detail"]

@pytest.mark.asyncio
async def test_forged_user_impersonation_rejected(client: AsyncClient):
    """P0-01: Attempting to impersonate a different user than signed token fails with 403."""
    token = create_internal_token(
        organization_id="org-pk-001",
        user_id="user-479",
        user_permissions=["accounting.view"],
    )
    payload = {
        "tool_name": "get_account",
        "arguments": {"account_code": "1010"},
        "user_id": "user-ceo-victim",  # Impersonation attack
    }
    response = await client.post(
        "/v1/tools/execute",
        json=payload,
        headers={"Authorization": f"Bearer {token}"},
    )
    assert response.status_code == 403
    assert "Identity mismatch" in response.json()["detail"]

@pytest.mark.asyncio
async def test_execute_read_tool_with_valid_token(client: AsyncClient):
    """Legitimate execution with valid signed token succeeds."""
    token = create_internal_token(
        organization_id="org-pk-001",
        user_id="user-479",
        user_permissions=["accounting.view"],
        entity_id="entity-karachi-01",
    )
    payload = {
        "tool_name": "get_account",
        "arguments": {"account_code": "1010"},
    }
    response = await client.post(
        "/v1/tools/execute",
        json=payload,
        headers={"Authorization": f"Bearer {token}"},
    )
    assert response.status_code == 200
    data = response.json()
    assert data["success"] is True
    assert data["data"]["account_code"] == "1010"

@pytest.mark.asyncio
async def test_balanced_journal_draft_succeeds(client: AsyncClient):
    """Draft tool with valid token and permission creates balanced draft."""
    token = create_internal_token(
        organization_id="org-pk-001",
        user_id="user-479",
        user_permissions=["accounting.journal.create"],
    )
    payload = {
        "tool_name": "draft_journal",
        "arguments": {
            "description": "Prepaid Rent Allocation",
            "lines": [
                {"account_code": "5010", "debit": 50000.0, "credit": 0.0},
                {"account_code": "1050", "debit": 0.0, "credit": 50000.0},
            ],
        },
    }
    response = await client.post(
        "/v1/tools/execute",
        json=payload,
        headers={"Authorization": f"Bearer {token}"},
    )
    assert response.status_code == 200
    data = response.json()
    assert data["success"] is True
    assert data["data"]["status"] == "pending_review"
    assert data["data"]["draft_id"] != "draft-jr-902"
    assert len(data["data"]["draft_id"]) >= 16

@pytest.mark.asyncio
async def test_unbalanced_journal_draft_rejected(client: AsyncClient):
    """Unbalanced draft is rejected by guardrails."""
    token = create_internal_token(
        organization_id="org-pk-001",
        user_id="user-479",
        user_permissions=["accounting.journal.create"],
    )
    payload = {
        "tool_name": "draft_journal",
        "arguments": {
            "description": "Unbalanced Journal",
            "lines": [
                {"account_code": "5010", "debit": 50000.0, "credit": 0.0},
                {"account_code": "1050", "debit": 0.0, "credit": 40000.0},  # Unbalanced by 10k
            ],
        },
    }
    response = await client.post(
        "/v1/tools/execute",
        json=payload,
        headers={"Authorization": f"Bearer {token}"},
    )
    assert response.status_code == 200
    data = response.json()
    assert data["success"] is False
    assert "Cannot create unbalanced journal draft" in data["error"]

@pytest.mark.asyncio
async def test_distributed_replay_protection_async_blocks_duplicate():
    """Verify ReplayProtection async distributed check rejects repeated nonce."""
    from apps.ai.src.auth.service_auth import ReplayProtection
    rp = ReplayProtection(ttl_seconds=60)
    nonce = "dist-nonce-abc-123"
    
    # First record succeeds
    assert await rp.check_and_record_async(nonce) is True
    # Replay check fails
    assert await rp.check_and_record_async(nonce) is False

def test_production_environment_rejects_default_secret():
    """Booting with ENVIRONMENT=production and default secret must raise ValueError."""
    from apps.ai.src.config import AISettings
    with pytest.raises(ValueError) as exc:
        s = AISettings(
            ENVIRONMENT="production",
            INTERNAL_SERVICE_SECRET="ai-native-finance-erp-internal-service-secret-key",
            DEFAULT_LLM_PROVIDER="gemini"
        )
        s.validate_production_readiness()
    assert "Security Violation: Production environment cannot use default insecure INTERNAL_SERVICE_SECRET" in str(exc.value)

def test_production_environment_rejects_mock_llm_adapter(monkeypatch):
    """Factory must refuse MockLLMAdapter in production."""
    from apps.ai.src.adapters.factory import get_llm_adapter
    from apps.ai.src.config import settings
    monkeypatch.setattr(settings, "ENVIRONMENT", "production")
    monkeypatch.setattr(settings, "DEFAULT_LLM_PROVIDER", "mock")
    
    with pytest.raises(RuntimeError) as exc:
        get_llm_adapter("mock")
    assert "MockLLMAdapter is strictly forbidden in production" in str(exc.value)

@pytest.mark.asyncio
async def test_tools_declare_complete_p1_11_metadata():
    """P1-11: Every registered tool must declare full scope and policy metadata."""
    from apps.ai.src.tools.registry import registry
    tools = registry.list_tools()
    assert len(tools) >= 4
    for tool in tools:
        assert isinstance(tool.permission, str) and len(tool.permission) > 0
        assert isinstance(tool.tenant_scope, bool)
        assert isinstance(tool.entity_scope, bool)
        assert isinstance(tool.side_effects, bool)
        assert isinstance(tool.approval_required, bool)
        assert isinstance(tool.idempotent, bool)
        assert isinstance(tool.audit_event, str) and len(tool.audit_event) > 0
        assert isinstance(tool.timeout_seconds, float) and tool.timeout_seconds > 0
        assert "max_retries" in tool.retry_policy
        assert "backoff_seconds" in tool.retry_policy

@pytest.mark.asyncio
async def test_ai_approval_gate_intercepts_unapproved_side_effects(client: AsyncClient):
    """P1-09 & P1-11: Side effect tools requiring approval are intercepted by AI gate."""
    token = create_internal_token(
        organization_id="org-pk-001",
        user_id="user-479",
        user_permissions=["accounting.journal.post"],
    )
    payload = {
        "tool_name": "execute_ledger_adjustment",
        "arguments": {
            "draft_id": "draft-uuid-12345",
            "reason": "Auditor year-end adjustment",
        },
    }
    response = await client.post(
        "/v1/tools/execute",
        json=payload,
        headers={"Authorization": f"Bearer {token}"},
    )
    assert response.status_code == 200
    res = response.json()
    assert res["success"] is True
    assert res["status"] == "pending_approval"
    assert res["requires_approval"] is True
    assert res["approval_context"]["tool_name"] == "execute_ledger_adjustment"
    assert res["approval_context"]["organization_id"] == "org-pk-001"
    assert res["data"]["gate_status"] == "pending_human_review"
    assert "requires explicit human approval" in res["data"]["message"]

@pytest.mark.asyncio
async def test_ai_approval_gate_rejects_tampered_approval_token(client: AsyncClient):
    """P1-09: Post-approval argument tampering must be caught cryptographically."""
    from apps.ai.src.auth.service_auth import create_approval_token
    token = create_internal_token(
        organization_id="org-pk-001",
        user_id="user-479",
        user_permissions=["accounting.journal.post"],
    )
    original_args = {"draft_id": "draft-uuid-12345", "reason": "Authorized reason"}
    approval_token = create_approval_token(
        tool_name="execute_ledger_adjustment",
        arguments=original_args,
        organization_id="org-pk-001",
        approver_user_id="cfo-user-999",
    )
    tampered_payload = {
        "tool_name": "execute_ledger_adjustment",
        "arguments": {
            "draft_id": "draft-uuid-MALICIOUS-999",
            "reason": "Authorized reason",
        },
        "approval_token": approval_token,
    }
    response = await client.post(
        "/v1/tools/execute",
        json=tampered_payload,
        headers={"Authorization": f"Bearer {token}"},
    )
    assert response.status_code == 200
    res = response.json()
    assert res["success"] is False
    assert res["status"] == "failed"
    assert "Tampering detected" in res["error"]

@pytest.mark.asyncio
async def test_ai_approval_gate_executes_with_valid_cryptographic_token(client: AsyncClient):
    """P1-09: Valid approval token passes gate and executes capability."""
    from apps.ai.src.auth.service_auth import create_approval_token
    token = create_internal_token(
        organization_id="org-pk-001",
        user_id="user-479",
        user_permissions=["accounting.journal.post"],
    )
    args = {"draft_id": "draft-uuid-12345", "reason": "Approved year-end allocation"}
    approval_token = create_approval_token(
        tool_name="execute_ledger_adjustment",
        arguments=args,
        organization_id="org-pk-001",
        approver_user_id="cfo-user-999",
    )
    payload = {
        "tool_name": "execute_ledger_adjustment",
        "arguments": args,
        "approval_token": approval_token,
    }
    response = await client.post(
        "/v1/tools/execute",
        json=payload,
        headers={"Authorization": f"Bearer {token}"},
    )
    assert response.status_code == 200
    res = response.json()
    assert res["success"] is True
    assert res["status"] == "completed"
    assert res["requires_approval"] is False
    assert res["data"]["status"] == "approved"


