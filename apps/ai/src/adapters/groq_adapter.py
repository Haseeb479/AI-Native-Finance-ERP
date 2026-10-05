from typing import Type
import json
import httpx
from apps.ai.src.adapters.base import BaseLLMAdapter, T
from apps.ai.src.config import settings


class GroqLLMAdapter(BaseLLMAdapter):
    """Groq chat-completions adapter with validated structured JSON responses."""

    endpoint = "https://api.groq.com/openai/v1/chat/completions"

    def __init__(self, api_key: str = None, model: str = None):
        self.api_key = api_key or settings.GROQ_API_KEY
        self.model = model or settings.GROQ_MODEL

    def provider_name(self) -> str:
        return "groq"

    async def generate_structured(
        self,
        prompt: str,
        system_instruction: str,
        response_model: Type[T],
        temperature: float = 0.1,
    ) -> T:
        if not self.api_key:
            raise ValueError("Groq API key is not configured in environment.")

        schema = json.dumps(response_model.model_json_schema())
        payload = {
            "model": self.model,
            "messages": [
                {
                    "role": "system",
                    "content": f"{system_instruction}\nReturn valid JSON matching this schema:\n{schema}",
                },
                {"role": "user", "content": prompt},
            ],
            "temperature": temperature,
            "response_format": {"type": "json_object"},
        }
        headers = {
            "Authorization": f"Bearer {self.api_key}",
            "Content-Type": "application/json",
        }

        async with httpx.AsyncClient(timeout=30.0) as client:
            response = await client.post(self.endpoint, headers=headers, json=payload)
            response.raise_for_status()
            content = response.json()["choices"][0]["message"]["content"]
            return response_model.model_validate_json(content)
