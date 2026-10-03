from fastapi import APIRouter, Depends
from apps.ai.src.api.v1.health import router as health_router
from apps.ai.src.api.v1.tools import router as tools_router
from apps.ai.src.api.v1.classify import router as classify_router
from apps.ai.src.api.v1.extract import router as extract_router
from apps.ai.src.api.v1.copilot import router as copilot_router
from apps.ai.src.api.v1.workflows import router as workflows_router
from apps.ai.src.auth.service_auth import require_verified_claims

api_v1_router = APIRouter(prefix="/v1")
api_v1_router.include_router(health_router, tags=["Health"])
api_v1_router.include_router(tools_router, tags=["Tools"])
api_v1_router.include_router(classify_router, tags=["Classification"], dependencies=[Depends(require_verified_claims)])
api_v1_router.include_router(extract_router, tags=["OCR & Extraction"], dependencies=[Depends(require_verified_claims)])
api_v1_router.include_router(copilot_router, tags=["AI Copilot"], dependencies=[Depends(require_verified_claims)])
api_v1_router.include_router(workflows_router, tags=["AI Financial Workflows"], dependencies=[Depends(require_verified_claims)])
