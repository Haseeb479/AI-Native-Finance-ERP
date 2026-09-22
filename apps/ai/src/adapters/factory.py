from apps.ai.src.adapters.base import BaseLLMAdapter
from apps.ai.src.adapters.mock_adapter import MockLLMAdapter
from apps.ai.src.adapters.gemini_adapter import GeminiLLMAdapter
from apps.ai.src.config import settings

def get_llm_adapter(provider: str = None) -> BaseLLMAdapter:
    """
    Factory function returning configured LLM adapter based on provider name.
    """
    prov = (provider or settings.DEFAULT_LLM_PROVIDER).lower()
    
    if prov == "gemini":
        return GeminiLLMAdapter()
    
    # Fallback to deterministic mock adapter for offline and test runs
    return MockLLMAdapter()
