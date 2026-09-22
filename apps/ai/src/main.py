import sys
from pathlib import Path

# Add project root to sys.path
_root = Path(__file__).resolve().parent.parent.parent
if str(_root) not in sys.path:
    sys.path.insert(0, str(_root))

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from apps.ai.src.config import settings
from apps.ai.src.api.v1 import api_v1_router
from apps.ai.src.tools.read_tools import register_read_tools
from apps.ai.src.tools.draft_tools import register_draft_tools

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
