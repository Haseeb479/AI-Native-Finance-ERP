import time
import uuid
from typing import Dict, List, Optional
import jwt
from pydantic import BaseModel
from fastapi import Header, HTTPException, Depends
from apps.ai.src.config import settings

class VerifiedClaims(BaseModel):
    iss: str
    aud: str
    organization_id: str
    entity_id: Optional[str] = None
    user_id: str
    user_permissions: List[str]
    jti: str
    exp: int
    iat: int

try:
    import redis.asyncio as aioredis
except ImportError:
    aioredis = None

class ReplayProtection:
    """
    Distributed Redis-backed replay protection with in-memory TTL fallback for offline/isolated tests.
    """
    def __init__(self, ttl_seconds: int = 300, redis_url: Optional[str] = None):
        self._seen_nonces: Dict[str, float] = {}
        self._ttl = ttl_seconds
        self._redis_url = redis_url or settings.REDIS_URL
        self._redis_client = None
        if self._redis_url and aioredis:
            try:
                self._redis_client = aioredis.from_url(self._redis_url, decode_responses=True)
            except Exception:
                self._redis_client = None

    async def check_and_record_async(self, jti: str) -> bool:
        if self._redis_client:
            try:
                # Atomic SET key value EX ttl NX: returns True only if key was newly set
                was_set = await self._redis_client.set(f"ai_nonce:{jti}", "1", ex=self._ttl, nx=True)
                return bool(was_set)
            except Exception:
                pass  # Fall back to in-memory

        return self.check_and_record(jti)

    def check_and_record(self, jti: str) -> bool:
        now = time.time()
        # Purge expired nonces
        self._seen_nonces = {k: exp for k, exp in self._seen_nonces.items() if exp > now}
        if jti in self._seen_nonces:
            return False  # Replay detected
        self._seen_nonces[jti] = now + self._ttl
        return True

    def clear(self):
        self._seen_nonces.clear()

replay_cache = ReplayProtection()


def create_internal_token(
    organization_id: str,
    user_id: str,
    user_permissions: List[str],
    entity_id: Optional[str] = None,
    nonce: Optional[str] = None,
    expires_in_seconds: int = 60,
    secret: Optional[str] = None,
    issuer: Optional[str] = None,
    audience: Optional[str] = None,
) -> str:
    """Helper to generate signed internal tokens for tests and service communication."""
    now = int(time.time())
    payload = {
        "iss": issuer or settings.SERVICE_ISSUER,
        "aud": audience or settings.SERVICE_AUDIENCE,
        "organization_id": organization_id,
        "entity_id": entity_id,
        "user_id": user_id,
        "user_permissions": user_permissions,
        "jti": nonce or str(uuid.uuid4()),
        "iat": now,
        "exp": now + expires_in_seconds,
    }
    return jwt.encode(payload, secret or settings.INTERNAL_SERVICE_SECRET, algorithm=settings.JWT_ALGORITHM)

async def require_verified_claims(
    authorization: Optional[str] = Header(None)
) -> VerifiedClaims:
    """
    FastAPI dependency: verifies signed service token, enforces issuer, audience,
    expiration, signature integrity, and replay protection.
    """
    if not authorization:
        raise HTTPException(
            status_code=401,
            detail="Missing Authorization header. Service authentication required."
        )

    scheme, _, token = authorization.partition(" ")
    if scheme.lower() != "bearer" or not token:
        raise HTTPException(
            status_code=401,
            detail="Invalid Authorization scheme. Expected 'Bearer <signed_token>'."
        )

    try:
        payload = jwt.decode(
            token,
            settings.INTERNAL_SERVICE_SECRET,
            algorithms=[settings.JWT_ALGORITHM],
            audience=settings.SERVICE_AUDIENCE,
            issuer=settings.SERVICE_ISSUER,
            options={"require": ["exp", "iss", "aud", "jti", "organization_id", "user_id"]}
        )
    except jwt.ExpiredSignatureError:
        raise HTTPException(status_code=401, detail="Internal service token has expired.")
    except jwt.InvalidTokenError as e:
        raise HTTPException(status_code=401, detail=f"Invalid service token: {str(e)}")

    jti = payload.get("jti")
    nonce_valid = await replay_cache.check_and_record_async(jti) if jti else False
    if not nonce_valid:
        raise HTTPException(
            status_code=401,
            detail="Replay attack detected: token nonce has already been used."
        )

    return VerifiedClaims(
        iss=payload["iss"],
        aud=payload["aud"],
        organization_id=payload["organization_id"],
        entity_id=payload.get("entity_id"),
        user_id=payload["user_id"],
        user_permissions=payload.get("user_permissions", []),
        jti=jti,
        exp=payload["exp"],
        iat=payload["iat"],
    )
