from __future__ import annotations
import json
import logging
import pytest
from httpx import AsyncClient
from apps.ai.src.observability.logging import (
    correlation_id_ctx,
    organization_id_ctx,
    user_id_ctx,
    mask_sensitive_data,
    StructuredJsonFormatter,
)

@pytest.mark.asyncio
async def test_correlation_id_echoed_in_response(client: AsyncClient):
    """P1-35: Inbound X-Correlation-ID must be propagated to response headers."""
    trace_id = "test-ai-trace-uuid-12345"
    response = await client.get("/health", headers={"X-Correlation-ID": trace_id})
    assert response.status_code == 200
    assert response.headers.get("X-Correlation-ID") == trace_id

@pytest.mark.asyncio
async def test_correlation_id_generated_when_missing(client: AsyncClient):
    """P1-35: Requests missing correlation ID must be assigned an autonomous UUID."""
    response = await client.get("/health")
    assert response.status_code == 200
    corr_id = response.headers.get("X-Correlation-ID")
    assert corr_id is not None
    assert len(corr_id) >= 10

def test_structured_json_logging_masks_secrets():
    """P1-35: Sensitive credentials, API keys, and JWTs must be redacted from structured logs."""
    raw_payload = {
        "user_email": "cfo@company.com",
        "password": "secret_cleartext_password",
        "api_key": "live_key_999888777",
        "internal_secret": "prod_service_secret_key",
        "approval_token": "token-12345",
        "nested": {
            "raw_header_value": "Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.dummy_signature",
            "card_number": "4111222233334444",
            "public_metric": "100.50",
        },
    }
    masked = mask_sensitive_data(raw_payload)

    assert masked["password"] == "[REDACTED]"
    assert masked["api_key"] == "[REDACTED]"
    assert masked["internal_secret"] == "[REDACTED]"
    assert masked["approval_token"] == "[REDACTED]"
    assert masked["nested"]["card_number"] == "[REDACTED]"
    assert masked["nested"]["raw_header_value"] == "[REDACTED_JWT]"
    assert masked["nested"]["public_metric"] == "100.50"

def test_json_formatter_includes_correlation_and_tenant_context():
    """P1-35: Formatter must inject correlation_id, organization_id, and user_id into JSON log lines."""
    token_c = correlation_id_ctx.set("corr-xyz-888")
    token_o = organization_id_ctx.set("org-corp-777")
    token_u = user_id_ctx.set("user-admin-111")

    try:
        formatter = StructuredJsonFormatter()
        record = logging.LogRecord(
            name="test_logger",
            level=logging.INFO,
            pathname="test.py",
            lineno=1,
            msg="Copilot query completed successfully",
            args=(),
            exc_info=None,
        )
        record.execution_time_ms = 42.5

        output = formatter.format(record)
        parsed = json.loads(output)

        assert parsed["level"] == "INFO"
        assert parsed["message"] == "Copilot query completed successfully"
        assert parsed["correlation_id"] == "corr-xyz-888"
        assert parsed["organization_id"] == "org-corp-777"
        assert parsed["user_id"] == "user-admin-111"
        assert parsed["execution_time_ms"] == 42.5
    finally:
        correlation_id_ctx.reset(token_c)
        organization_id_ctx.reset(token_o)
        user_id_ctx.reset(token_u)
