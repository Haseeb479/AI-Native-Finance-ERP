from pydantic import BaseModel, Field
from typing import Dict, Any, List

class ServiceStatus(BaseModel):
    status: str
    details: Dict[str, Any] = Field(default_factory=dict)

class HealthResponse(BaseModel):
    status: str
    version: str
    environment: str
    active_llm_provider: str
    services: Dict[str, ServiceStatus]
    errors: List[str] = Field(default_factory=list)
