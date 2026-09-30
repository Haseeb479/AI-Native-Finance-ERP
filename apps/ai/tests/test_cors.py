import pytest
from fastapi.testclient import TestClient
from apps.ai.src.main import app
from apps.ai.src.config import AISettings

client = TestClient(app)

def test_cors_preflight_allowed_origin():
    """Verify that trusted origins receive proper CORS headers."""
    response = client.options(
        "/api/v1/copilot/chat",
        headers={
            "Origin": "http://localhost:3000",
            "Access-Control-Request-Method": "POST",
            "Access-Control-Request-Headers": "authorization,content-type,x-correlation-id",
        },
    )
    assert response.status_code == 200
    assert response.headers.get("access-control-allow-origin") == "http://localhost:3000"
    assert response.headers.get("access-control-allow-credentials") == "true"
    assert "POST" in response.headers.get("access-control-allow-methods", "")

def test_cors_preflight_disallowed_origin():
    """Verify that untrusted origins do not receive allow-origin header."""
    response = client.options(
        "/api/v1/copilot/chat",
        headers={
            "Origin": "http://malicious-domain.com",
            "Access-Control-Request-Method": "POST",
        },
    )
    assert response.headers.get("access-control-allow-origin") is None

def test_production_readiness_validations():
    """Verify that AISettings rejects unsafe production setups (P1-34)."""
    # 1. Unsafe secret in production
    with pytest.raises(ValueError, match="Security Violation"):
        AISettings(
            ENVIRONMENT="production",
            INTERNAL_SERVICE_SECRET="ai-native-finance-erp-internal-service-secret-key",
            DEFAULT_LLM_PROVIDER="gemini",
            GEMINI_API_KEY="test-key",
        ).validate_production_readiness()

    # 2. Mock provider in production
    with pytest.raises(ValueError, match="Configuration Violation: Production environment cannot use 'mock'"):
        AISettings(
            ENVIRONMENT="production",
            INTERNAL_SERVICE_SECRET="super-secure-production-secret-12345",
            DEFAULT_LLM_PROVIDER="mock",
        ).validate_production_readiness()

    # 3. Missing API key for gemini provider in production
    with pytest.raises(ValueError, match="GEMINI_API_KEY must be configured"):
        AISettings(
            ENVIRONMENT="production",
            INTERNAL_SERVICE_SECRET="super-secure-production-secret-12345",
            DEFAULT_LLM_PROVIDER="gemini",
            GEMINI_API_KEY=None,
        ).validate_production_readiness()
