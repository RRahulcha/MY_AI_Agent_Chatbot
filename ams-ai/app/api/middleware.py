import time
import hmac
import hashlib
import base64
import json
import logging
from typing import Dict, Tuple, Any
from fastapi import Request, status
from fastapi.responses import JSONResponse
from starlette.middleware.base import BaseHTTPMiddleware
from app.db.mysql import execute_query
from app.config import settings

logger = logging.getLogger("ams-ai.auth")

class UserContext:
    def __init__(self, user_id: str, role: str, designation: str, full_name: str, email: str, status_val: str):
        self.user_id = user_id
        self.role = role
        self.designation = designation
        self.full_name = full_name
        self.email = email
        self.status = status_val

# In-memory user cache: user_id -> (UserContext, timestamp)
USER_CACHE: Dict[str, Tuple[UserContext, float]] = {}
CACHE_TTL = 300  # 5 minutes

def verify_internal_token(auth_header: str, secret: str) -> Tuple[bool, Dict[str, Any], str]:
    """Cryptographically verifies HMAC-SHA256 bearer token minted by the PHP auth proxy."""
    if not auth_header or not auth_header.startswith("Bearer "):
        return False, {}, "Missing or invalid Authorization header format."
    token = auth_header[7:].strip()
    parts = token.split(".")
    if len(parts) != 3:
        return False, {}, "Malformed token structure."
    
    b64_header, b64_payload, b64_signature = parts
    
    # 1. Verify HMAC-SHA256 signature
    msg = f"{b64_header}.{b64_payload}".encode("utf-8")
    expected_sig = hmac.new(secret.encode("utf-8"), msg, hashlib.sha256).digest()
    expected_b64 = base64.urlsafe_b64encode(expected_sig).decode("utf-8").rstrip("=")
    
    if not hmac.compare_digest(b64_signature, expected_b64):
        return False, {}, "Invalid cryptographic token signature."
    
    # 2. Decode payload
    try:
        padding = 4 - (len(b64_payload) % 4)
        if padding != 4:
            b64_payload_padded = b64_payload + ("=" * padding)
        else:
            b64_payload_padded = b64_payload
        payload_bytes = base64.urlsafe_b64decode(b64_payload_padded)
        payload = json.loads(payload_bytes.decode("utf-8"))
    except Exception as e:
        return False, {}, f"Invalid token encoding: {e}"
    
    # 3. Check expiration (60s max TTL)
    exp = payload.get("exp")
    if not exp or time.time() > float(exp):
        return False, {}, "Token has expired."
    
    # 4. Check subject claim
    if "sub" not in payload or not payload["sub"]:
        return False, {}, "Missing subject (sub) claim."
        
    return True, payload, ""

class SessionAuthMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next):
        path = request.url.path
        # Exclude root, favicon, health, readiness, and CORS preflight OPTIONS requests
        if request.method == "OPTIONS" or path in ["/", "/favicon.ico", "/api/health", "/api/health/live", "/api/ready", "/api/status", "/api/metrics", "/docs", "/openapi.json"]:
            return await call_next(request)

        # 1. Extract and verify token
        auth_header = request.headers.get("Authorization") or ""
        secret = settings.AI_INTERNAL_SECRET

        if secret:
            valid, payload, err_msg = verify_internal_token(auth_header, secret)
            if not valid:
                logger.warning(f"Access denied: {err_msg}. Path: {path}")
                return JSONResponse(
                    status_code=status.HTTP_401_UNAUTHORIZED,
                    content={"success": False, "message": f"Unauthorized: {err_msg}"}
                )
            user_id = payload["sub"]
            user_role = payload.get("role", "junior_intern")
            designation = payload.get("designation", "intern")
        else:
            # Fallback for development if secret not yet generated
            user_id = request.headers.get("x-user-id")
            user_role = request.headers.get("x-user-role") or "junior_intern"
            designation = request.headers.get("x-user-designation") or "intern"
            if not user_id:
                return JSONResponse(
                    status_code=status.HTTP_401_UNAUTHORIZED,
                    content={"success": False, "message": "Authentication required. Missing verified token."}
                )

        # 2. Check in-memory user cache
        now = time.time()
        cached = USER_CACHE.get(user_id)
        if cached and (now - cached[1] < CACHE_TTL):
            request.state.user = cached[0]
            return await call_next(request)

        # 3. Verify identity against MySQL signup table
        try:
            user_data = execute_query(
                "SELECT userId, userRole, designation, fullName, email, status FROM signup WHERE userId = ? LIMIT 1",
                (user_id,),
                fetch="one"
            )
            
            if user_data:
                # Block inactive accounts
                if str(user_data.get("status", "")).lower() == "inactive":
                    logger.warning(f"Access denied: User {user_id} is inactive.")
                    return JSONResponse(
                        status_code=status.HTTP_403_FORBIDDEN,
                        content={"success": False, "message": "Your account has been deactivated."}
                    )

                u_ctx = UserContext(
                    user_id=user_data["userId"],
                    role=user_data.get("userRole") or user_role,
                    designation=user_data.get("designation") or designation,
                    full_name=user_data.get("fullName") or "User",
                    email=user_data.get("email") or "",
                    status_val=user_data.get("status") or "Active"
                )
                USER_CACHE[user_id] = (u_ctx, now)
                request.state.user = u_ctx
            else:
                # Fallback to token context if user exists in database with non-standard id
                u_ctx = UserContext(
                    user_id=user_id,
                    role=user_role,
                    designation=designation,
                    full_name="User",
                    email="",
                    status_val="Active"
                )
                USER_CACHE[user_id] = (u_ctx, now)
                request.state.user = u_ctx

        except Exception as e:
            logger.warning(f"Database auth check error: {e}. Utilizing token payload context.")
            u_ctx = UserContext(
                user_id=user_id,
                role=user_role,
                designation=designation,
                full_name="User",
                email="",
                status_val="Active"
            )
            USER_CACHE[user_id] = (u_ctx, now)
            request.state.user = u_ctx

        return await call_next(request)
