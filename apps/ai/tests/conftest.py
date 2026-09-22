import sys
from pathlib import Path
import pytest
from httpx import AsyncClient, ASGITransport

# Ensure repo root is on sys.path so apps.ai imports resolve
repo_root = Path(__file__).resolve().parent.parent.parent.parent
if str(repo_root) not in sys.path:
    sys.path.insert(0, str(repo_root))

from apps.ai.src.main import app

@pytest.fixture
async def client():
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as ac:
        yield ac
