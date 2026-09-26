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
    
    # Distributed Cache / Replay Protection (P1-10)
    REDIS_URL: Optional[str] = None
    
    # LLM Provider Configuration
    DEFAULT_LLM_PROVIDER: str = "mock"  # "gemini", "openai", "anthropic", "mock"
    GEMINI_API_KEY: Optional[str] = None
    OPENAI_API_KEY: Optional[str] = None
    ANTHROPIC_API_KEY: Optional[str] = None
    
    # Safety Limits
    MAX_TOKENS_PER_REQUEST: int = 4096
    TEMPERATURE: float = 0.1  # Low temperature for deterministic accounting outputs
    
    # Internal Service-to-Service Authentication (P0-01)
    INTERNAL_SERVICE_SECRET: str = "ai-native-finance-erp-internal-service-secret-key"
    JWT_ALGORITHM: str = "HS256"
    SERVICE_ISSUER: str = "laravel-finance-erp"
    SERVICE_AUDIENCE: str = "ai-tool-gateway"
    
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore"
    )

    def validate_production_readiness(self):
        """Strict production security assertions."""
        if self.ENVIRONMENT == "production":
            if self.INTERNAL_SERVICE_SECRET == "ai-native-finance-erp-internal-service-secret-key":
                raise ValueError("Security Violation: Production environment cannot use default insecure INTERNAL_SERVICE_SECRET.")
            if self.DEFAULT_LLM_PROVIDER == "mock":
                raise ValueError("Configuration Violation: Production environment cannot use 'mock' LLM provider.")

settings = AISettings()
settings.validate_production_readiness()

