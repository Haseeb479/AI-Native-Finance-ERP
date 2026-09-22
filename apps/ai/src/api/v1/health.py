from fastapi import APIRouter
from apps.ai.src.schemas.health import HealthResponse, ServiceStatus
from apps.ai.src.config import settings
from apps.ai.src.adapters.factory import get_llm_adapter

router = APIRouter()

@router.get("/health", response_model=HealthResponse)
async def health_check():
    adapter = get_llm_adapter()
    return HealthResponse(
        status="healthy",
        version="1.0.0",
        environment=settings.ENVIRONMENT,
        active_llm_provider=adapter.provider_name(),
        services={
            "llm_gateway": ServiceStatus(
                status="online",
                details={"provider": adapter.provider_name(), "temperature": settings.TEMPERATURE},
            ),
            "guardrails": ServiceStatus(
                status="active",
                details={"mutation_prohibition": True, "prompt_injection_defense": True},
            ),
        },
        errors=[],
    )
