from abc import ABC, abstractmethod
from typing import Dict, Any, Type, TypeVar
from pydantic import BaseModel

T = TypeVar("T", bound=BaseModel)

class BaseLLMAdapter(ABC):
    """
    Abstract adapter for provider-independent LLM calling with structured output.
    """
    @abstractmethod
    async def generate_structured(
        self,
        prompt: str,
        system_instruction: str,
        response_model: Type[T],
        temperature: float = 0.1,
    ) -> T:
        """
        Generate structured output adhering to a Pydantic response_model.
        """
        pass

    @abstractmethod
    def provider_name(self) -> str:
        """
        Return the provider identifier (e.g. 'gemini', 'openai', 'mock').
        """
        pass
