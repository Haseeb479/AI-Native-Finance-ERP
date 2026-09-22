from pydantic_settings import BaseSettings, SettingsConfigDict
from typing import Optional

class AISettings(BaseSettings):
    """
    AI Service Configuration with environment fallbacks.
    Never commits or exposes production credentials.
    """
    APP_NAME: str = "AI-Native Finance ERP AI Service"
    ENVIRONMENT: str = "local"
    DEBUG: bool = True
    HOST: str = "127.0.0.1"
    PORT: int = 8001
    
    # Core API Service URL (Laravel Backend)
    BACKEND_API_URL: str = "http://127.0.0.1:8000/api/v1"
    
    # LLM Provider Configuration
    DEFAULT_LLM_PROVIDER: str = "mock"  # "gemini", "openai", "anthropic", "mock"
    GEMINI_API_KEY: Optional[str] = None
    OPENAI_API_KEY: Optional[str] = None
    ANTHROPIC_API_KEY: Optional[str] = None
    
    # Safety Limits
    MAX_TOKENS_PER_REQUEST: int = 4096
    TEMPERATURE: float = 0.1  # Low temperature for deterministic accounting outputs
    
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore"
    )

settings = AISettings()
