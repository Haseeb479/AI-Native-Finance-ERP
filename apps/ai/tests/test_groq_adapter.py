import json
import pytest
from apps.ai.src.adapters.groq_adapter import GroqLLMAdapter
from apps.ai.src.schemas.qa import FinancialQAResponse


@pytest.mark.asyncio
async def test_groq_adapter_validates_structured_response(monkeypatch):
    captured = {}

    class MockResponse:
        def raise_for_status(self):
            pass

        def json(self):
            return {
                "choices": [
                    {
                        "message": {
                            "content": json.dumps(
                                {
                                    "answer": "Cash-flow drivers require review of current ledger evidence.",
                                    "key_metrics": {},
                                    "evidence": [],
                                    "suggested_actions": [],
                                    "confidence": 0.8,
                                    "groundedness_score": 1.0,
                                    "flagged_for_review": False,
                                }
                            )
                        }
                    }
                ]
            }

    class MockAsyncClient:
        def __init__(self, timeout):
            captured["timeout"] = timeout

        async def __aenter__(self):
            return self

        async def __aexit__(self, *_args):
            return False

        async def post(self, endpoint, headers, json):
            captured.update(endpoint=endpoint, headers=headers, payload=json)
            return MockResponse()

    monkeypatch.setattr(
        "apps.ai.src.adapters.groq_adapter.httpx.AsyncClient",
        MockAsyncClient,
    )

    adapter = GroqLLMAdapter(api_key="test-key", model="test-model")
    response = await adapter.generate_structured(
        prompt="Analyze cash-flow drivers.",
        system_instruction="Answer from supplied evidence.",
        response_model=FinancialQAResponse,
    )

    assert adapter.provider_name() == "groq"
    assert response.answer.startswith("Cash-flow drivers")
    assert captured["endpoint"] == "https://api.groq.com/openai/v1/chat/completions"
    assert captured["payload"]["model"] == "test-model"
    assert captured["payload"]["response_format"] == {"type": "json_object"}
    assert '"properties"' in captured["payload"]["messages"][0]["content"]


@pytest.mark.asyncio
async def test_groq_adapter_requires_api_key():
    adapter = GroqLLMAdapter(api_key="")

    with pytest.raises(ValueError, match="Groq API key is not configured"):
        await adapter.generate_structured(
            prompt="Question",
            system_instruction="Instructions",
            response_model=FinancialQAResponse,
        )
