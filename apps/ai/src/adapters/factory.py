from apps.ai.src.adapters.base import BaseLLMAdapter
from apps.ai.src.adapters.mock_adapter import MockLLMAdapter
from apps.ai.src.adapters.gemini_adapter import GeminiLLMAdapter
from apps.ai.src.adapters.groq_adapter import GroqLLMAdapter
from apps.ai.src.config import settings

def get_llm_adapter(provider: str = None) -> BaseLLMAdapter:
    """
    Factory function returning configured LLM adapter based on provider name.
    Strictly forbids MockLLMAdapter in production environments.
    """
    prov = (provider or settings.DEFAULT_LLM_PROVIDER).lower()
    
    if prov == "gemini":
        return GeminiLLMAdapter()

    if prov == "groq":
        return GroqLLMAdapter()

    if prov == "mock" and settings.ENVIRONMENT != "production":
        return MockLLMAdapter()

    if settings.ENVIRONMENT == "production":
        if prov == "mock":
            raise RuntimeError(
                "Security Violation: MockLLMAdapter is strictly forbidden in production."
            )
        raise RuntimeError(
            f"Security Violation: LLM provider '{prov}' is not supported for production. "
            "Configure a supported provider and credentials."
        )

    raise ValueError(f"Unsupported LLM provider: '{prov}'. Configure 'groq', 'gemini', or 'mock'.")
