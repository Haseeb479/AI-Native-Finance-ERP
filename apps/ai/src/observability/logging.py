from __future__ import annotations
import json
import logging
import sys
import time
import uuid
from contextvars import ContextVar
from datetime import datetime, timezone
from typing import Dict, Any, Optional, List

# Distributed correlation ID context variable (P1-35)
correlation_id_ctx: ContextVar[Optional[str]] = ContextVar("correlation_id", default=None)
organization_id_ctx: ContextVar[Optional[str]] = ContextVar("organization_id", default=None)
user_id_ctx: ContextVar[Optional[str]] = ContextVar("user_id", default=None)

SENSITIVE_KEYS = {
    "password",
    "secret",
    "token",
    "authorization",
    "bearer",
    "api_key",
    "internal_secret",
    "approval_token",
    "card_number",
    "cvv",
}

def mask_sensitive_data(data: Any) -> Any:
    """Recursively mask sensitive values to prevent credential and secret leakage into logs."""
    if isinstance(data, dict):
        masked: Dict[str, Any] = {}
        for k, v in data.items():
            if any(s in k.lower() for s in SENSITIVE_KEYS):
                masked[k] = "[REDACTED]"
            else:
                masked[k] = mask_sensitive_data(v)
        return masked
    elif isinstance(data, list):
        return [mask_sensitive_data(item) for item in data]
    elif isinstance(data, str) and (data.startswith("Bearer ") or data.startswith("eyJhbGciOi")):
        return "[REDACTED_JWT]"
    return data

class StructuredJsonFormatter(logging.Formatter):
    """
    Standard Structured JSON Log Formatter with correlation ID,
    tenant metadata, and sensitive credential masking (P1-35).
    """
    def format(self, record: logging.LogRecord) -> str:
        log_obj: Dict[str, Any] = {
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "level": record.levelname,
            "logger": record.name,
            "message": record.getMessage(),
            "correlation_id": correlation_id_ctx.get(),
            "organization_id": organization_id_ctx.get(),
            "user_id": user_id_ctx.get(),
        }

        # Include custom extra fields if provided
        for key, val in record.__dict__.items():
            if key not in {
                "args", "asctime", "created", "exc_info", "exc_text", "filename",
                "funcName", "levelname", "levelno", "lineno", "module", "msecs",
                "message", "msg", "name", "pathname", "process", "processName",
                "relativeCreated", "stack_info", "thread", "threadName"
            }:
                log_obj[key] = mask_sensitive_data(val)

        if record.exc_info:
            log_obj["exception"] = self.formatException(record.exc_info)

        return json.dumps(log_obj, default=str)

def get_logger(name: str = "ai_microservice") -> logging.Logger:
    logger = logging.getLogger(name)
    if not logger.handlers:
        handler = logging.StreamHandler(sys.stdout)
        handler.setFormatter(StructuredJsonFormatter())
        logger.addHandler(handler)
        logger.setLevel(logging.INFO)
    return logger
