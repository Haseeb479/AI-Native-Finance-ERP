import sys
from pathlib import Path

# Add project root to sys.path
_root = Path(__file__).resolve().parent.parent.parent
if str(_root) not in sys.path:
    sys.path.insert(0, str(_root))

import uuid
import time
from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware
from starlette.middleware.base import BaseHTTPMiddleware
from apps.ai.src.config import settings
from apps.ai.src.api.v1 import api_v1_router
from apps.ai.src.tools.read_tools import register_read_tools
from apps.ai.src.tools.draft_tools import register_draft_tools
from apps.ai.src.observability.logging import correlation_id_ctx, organization_id_ctx, get_logger

logger = get_logger("ai_service")

class CorrelationIdMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next):
        corr_id = (
            request.headers.get("X-Correlation-ID")
            or request.headers.get("X-Request-ID")
            or str(uuid.uuid4())
        )
        token_corr = correlation_id_ctx.set(corr_id)

        org_id = request.headers.get("X-Organization-Id")
        token_org = organization_id_ctx.set(org_id) if org_id else None

        start_time = time.time()
        try:
            response = await call_next(request)
            response.headers["X-Correlation-ID"] = corr_id
            duration_ms = round((time.time() - start_time) * 1000, 2)
            logger.info(
                f"{request.method} {request.url.path} completed with status {response.status_code} in {duration_ms}ms"
            )
            return response
        finally:
            correlation_id_ctx.reset(token_corr)
            if token_org is not None:
                organization_id_ctx.reset(token_org)

# Initialize controlled typed tools
register_read_tools()
register_draft_tools()

app = FastAPI(
    title=settings.APP_NAME,
    version="1.0.0",
    description="Provider-agnostic AI agent gateway with controlled tools for AI-Native Finance ERP",
    docs_url="/docs" if settings.DEBUG else None,
    redoc_url="/redoc" if settings.DEBUG else None,
)

# Correlation and Distributed Tracing Middleware (P1-35)
app.add_middleware(CorrelationIdMiddleware)

# CORS configuration
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Mount routes
app.include_router(api_v1_router)

@app.get("/")
async def root():
    return {
        "service": settings.APP_NAME,
        "version": "1.0.0",
        "status": "online",
        "docs": "/docs",
    }

@app.get("/health")
async def health_alias():
    return {
        "status": "healthy",
        "service": settings.APP_NAME,
        "version": "1.0.0",
    }

if __name__ == "__main__":
    import uvicorn
    uvicorn.run("apps.ai.src.main:app", host=settings.HOST, port=settings.PORT, reload=settings.DEBUG)
