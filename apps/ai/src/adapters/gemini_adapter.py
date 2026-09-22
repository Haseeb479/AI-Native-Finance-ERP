from typing import Type
import json
import httpx
from apps.ai.src.adapters.base import BaseLLMAdapter, T
from apps.ai.src.config import settings

class GeminiLLMAdapter(BaseLLMAdapter):
    """
    Google Gemini adapter supporting structured JSON responses.
    """
    def __init__(self, api_key: str = None, model: str = "gemini-1.5-flash"):
        self.api_key = api_key or settings.GEMINI_API_KEY
        self.model = model
        self.endpoint = f"https://generativelanguage.googleapis.com/v1beta/models/{self.model}:generateContent"

    def provider_name(self) -> str:
        return "gemini"

    async def generate_structured(
        self,
        prompt: str,
        system_instruction: str,
        response_model: Type[T],
        temperature: float = 0.1,
    ) -> T:
        if not self.api_key:
            raise ValueError("Gemini API key is not configured in environment.")

        headers = {"Content-Type": "application/json"}
        params = {"key": self.api_key}

        payload = {
            "contents": [{"parts": [{"text": prompt}]}],
            "systemInstruction": {"parts": [{"text": system_instruction}]},
            "generationConfig": {
                "temperature": temperature,
                "responseMimeType": "application/json",
                "responseSchema": response_model.model_json_schema(),
            },
        }

        async with httpx.AsyncClient(timeout=30.0) as client:
            resp = await client.post(self.endpoint, headers=headers, params=params, json=payload)
            resp.raise_for_status()
            data = resp.json()
            raw_text = data["candidates"][0]["content"]["parts"][0]["text"]
            parsed = json.loads(raw_text)
            return response_model.model_validate(parsed)
