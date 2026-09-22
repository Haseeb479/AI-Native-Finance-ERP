import pytest
from httpx import AsyncClient

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
async def test_execute_read_tool_with_permission(client: AsyncClient):
    payload = {
        "tool_name": "get_account",
        "arguments": {"account_code": "1010"},
        "organization_id": "org-pk-001",
        "entity_id": "entity-karachi-01",
        "user_id": "user-479",
        "user_permissions": ["accounting.view"],
    }
    response = await client.post("/v1/tools/execute", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["success"] is True
    assert data["data"]["account_code"] == "1010"

@pytest.mark.asyncio
async def test_execute_tool_permission_denied(client: AsyncClient):
    payload = {
        "tool_name": "draft_journal",
        "arguments": {"description": "Unauthorized Draft", "lines": []},
        "organization_id": "org-pk-001",
        "entity_id": "entity-karachi-01",
        "user_id": "user-unauthorized",
        "user_permissions": ["accounting.view"],  # Lacks accounting.journal.create
    }
    response = await client.post("/v1/tools/execute", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["success"] is False
    assert "Permission denied" in data["error"]

@pytest.mark.asyncio
async def test_balanced_journal_draft_succeeds(client: AsyncClient):
    payload = {
        "tool_name": "draft_journal",
        "arguments": {
            "description": "Prepaid Rent Allocation",
            "lines": [
                {"account_code": "5010", "debit": 50000.0, "credit": 0.0},
                {"account_code": "1050", "debit": 0.0, "credit": 50000.0},
            ],
        },
        "organization_id": "org-pk-001",
        "entity_id": "entity-karachi-01",
        "user_id": "user-479",
        "user_permissions": ["accounting.journal.create"],
    }
    response = await client.post("/v1/tools/execute", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["success"] is True
    assert data["data"]["status"] == "draft_pending_review"

@pytest.mark.asyncio
async def test_unbalanced_journal_draft_rejected(client: AsyncClient):
    payload = {
        "tool_name": "draft_journal",
        "arguments": {
            "description": "Unbalanced Journal",
            "lines": [
                {"account_code": "5010", "debit": 50000.0, "credit": 0.0},
                {"account_code": "1050", "debit": 0.0, "credit": 40000.0},  # Unbalanced by 10,000!
            ],
        },
        "organization_id": "org-pk-001",
        "entity_id": "entity-karachi-01",
        "user_id": "user-479",
        "user_permissions": ["accounting.journal.create"],
    }
    response = await client.post("/v1/tools/execute", json=payload)
    assert response.status_code == 200
    data = response.json()
    assert data["success"] is False
    assert "Cannot create unbalanced journal draft" in data["error"]
