from fastapi import APIRouter
from apps.ai.src.api.v1.health import router as health_router
from apps.ai.src.api.v1.tools import router as tools_router
from apps.ai.src.api.v1.classify import router as classify_router
from apps.ai.src.api.v1.extract import router as extract_router
from apps.ai.src.api.v1.copilot import router as copilot_router

api_v1_router = APIRouter(prefix="/v1")
api_v1_router.include_router(health_router, tags=["Health"])
api_v1_router.include_router(tools_router, tags=["Tools"])
api_v1_router.include_router(classify_router, tags=["Classification"])
api_v1_router.include_router(extract_router, tags=["OCR & Extraction"])
api_v1_router.include_router(copilot_router, tags=["AI Copilot"])
