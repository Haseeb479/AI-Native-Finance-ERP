from apps.ai.src.adapters.base import BaseLLMAdapter
from apps.ai.src.adapters.mock_adapter import MockLLMAdapter
from apps.ai.src.adapters.gemini_adapter import GeminiLLMAdapter
from apps.ai.src.config import settings

def get_llm_adapter(provider: str = None) -> BaseLLMAdapter:
    """
    Factory function returning configured LLM adapter based on provider name.
    Strictly forbids MockLLMAdapter in production environments.
    """
    prov = (provider or settings.DEFAULT_LLM_PROVIDER).lower()
    
    if prov == "gemini":
        return GeminiLLMAdapter()
    
    if settings.ENVIRONMENT == "production":
        raise RuntimeError(
            f"Security Violation: MockLLMAdapter is strictly forbidden in production. Configured provider: '{prov}'. "
            "Please configure real provider credentials (e.g. GEMINI_API_KEY)."
        )

    # Fallback to deterministic mock adapter strictly for offline development and local test runs
    return MockLLMAdapter()

