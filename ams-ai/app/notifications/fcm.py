import os
import time
import json
import logging
import httpx
from typing import List, Dict, Any, Optional
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import padding
from cryptography.hazmat.primitives import hashes
import base64
from app.config import settings
from app.db.mysql import execute_query

logger = logging.getLogger("ams-ai.notifications.fcm")

# In-memory OAuth token cache
_cached_token: Optional[str] = None
_token_expiry: float = 0.0

def _base64url_encode(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b'=').decode('utf-8')

def _generate_fcm_oauth_token() -> str:
    """Generate a Google OAuth 2.0 Access Token using the FCM Service Account Key."""
    global _cached_token, _token_expiry
    
    # 1. Return cached token if valid
    if _cached_token and time.time() < _token_expiry - 60:
        return _cached_token

    # 2. Check service account file existence
    sa_path = settings.FCM_SERVICE_ACCOUNT_JSON
    if not sa_path:
        raise ValueError("FCM_SERVICE_ACCOUNT_JSON path not configured.")
        
    # Resolve relative path
    if not os.path.isabs(sa_path):
        sa_path = os.path.abspath(os.path.join(os.path.dirname(os.path.dirname(__file__)), sa_path))
        
    if not os.path.exists(sa_path):
        raise FileNotFoundError(f"FCM service account file not found at: {sa_path}")

    with open(sa_path, "r") as f:
        sa_data = json.load(f)

    # 3. Create signed JWT
    now = int(time.time())
    header = {"alg": "RS256", "typ": "JWT"}
    claims = {
        "iss": sa_data["client_email"],
        "scope": "https://www.googleapis.com/auth/firebase.messaging",
        "aud": "https://oauth2.googleapis.com/token",
        "iat": now,
        "exp": now + 3600
    }

    signing_input = f"{_base64url_encode(json.dumps(header).encode('utf-8'))}.{_base64url_encode(json.dumps(claims).encode('utf-8'))}"
    
    # Load private key
    private_key = serialization.load_pem_private_key(
        sa_data["private_key"].encode("utf-8"),
        password=None
    )
    
    signature = private_key.sign(
        signing_input.encode("utf-8"),
        padding.PKCS1v15(),
        hashes.SHA256()
    )
    
    jwt_token = f"{signing_input}.{_base64url_encode(signature)}"

    # 4. Exchange JWT for access token
    resp = httpx.post(
        "https://oauth2.googleapis.com/token",
        data={
            "grant_type": "urn:ietf:params:oauth:grant-type:jwt-bearer",
            "assertion": jwt_token
        },
        timeout=10
    )
    
    if resp.status_code != 200:
        raise RuntimeError(f"FCM OAuth exchange failed: {resp.status_code} | {resp.text}")

    resp_data = resp.json()
    _cached_token = resp_data["access_token"]
    _token_expiry = now + int(resp_data.get("expires_in", 3600))
    
    return _cached_token

def prune_dead_token(fcm_token: str):
    """Remove a dead/invalid token from the signup table to avoid redundant calls."""
    try:
        execute_query("UPDATE signup SET token = NULL WHERE token = ?", (fcm_token,), fetch="none")
        logger.info("Pruned dead FCM token from signup table.")
    except Exception as e:
        logger.error(f"Failed to prune dead token: {e}")

def send_fcm_push(fcm_token: str, title: str, body: str):
    """Dispatch async FCM push notification via HTTP v1 gateway."""
    try:
        access_token = _generate_fcm_oauth_token()
        project_id = settings.FCM_PROJECT_ID
        
        payload = {
            "message": {
                "token": fcm_token,
                "notification": {"title": title, "body": body},
                "data": {"title": title, "body": body, "click_action": "FLUTTER_NOTIFICATION_CLICK"},
                "android": {
                    "priority": "HIGH",
                    "notification": {"sound": "default", "channel_id": "high_importance_channel"}
                },
                "apns": {
                    "payload": {"aps": {"sound": "default", "content-available": 1}}
                }
            }
        }
        
        url = f"https://fcm.googleapis.com/v1/projects/{project_id}/messages:send"
        
        resp = httpx.post(
            url,
            json=payload,
            headers={
                "Authorization": f"Bearer {access_token}",
                "Content-Type": "application/json"
            },
            timeout=10
        )
        
        if resp.status_code in [400, 404]:
            # Prune dead token
            prune_dead_token(fcm_token)
        elif resp.status_code != 200:
            logger.error(f"FCM push failed with code {resp.status_code}: {resp.text}")
            
    except Exception as e:
        logger.warning(f"Failed to dispatch FCM push: {e}")

def send_notification_to_user(user_id: str, title: str, body: str, notif_type: str = "", reference_id: str = ""):
    """Insert notification into MySQL database and dispatch FCM push."""
    try:
        # Write to notifications table
        execute_query(
            """
            INSERT INTO notifications 
            (userId, title, message, type, reference_id, status, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, 'Unread', 0, NOW())
            """,
            (user_id, title, body, notif_type, reference_id if reference_id else None),
            fetch="none"
        )
        
        # Fetch target user's FCM token
        user_row = execute_query("SELECT token FROM signup WHERE userId = ? LIMIT 1", (user_id,), fetch="one")
        if user_row and user_row["token"]:
            send_fcm_push(user_row["token"], title, body)
            
    except Exception as e:
        logger.error(f"Failed to send notification to user {user_id}: {e}")

def send_notification_to_admins(title: str, body: str, notif_type: str = "", reference_id: str = ""):
    """Send notification to all active HR and Admin users."""
    try:
        admins = execute_query(
            """
            SELECT userId, token FROM signup 
            WHERE (userRole = 'admin' OR userRole = 'hr' OR designation = 'Admin' OR designation = 'hr' OR designation = 'HR')
              AND status = 'active'
            """,
            fetch="all"
        )
        
        for admin in admins:
            # Save notification record
            execute_query(
                """
                INSERT INTO notifications 
                (userId, title, message, type, reference_id, status, is_read, created_at)
                VALUES (?, ?, ?, ?, ?, 'Unread', 0, NOW())
                """,
                (admin["userId"], title, body, notif_type, reference_id if reference_id else None),
                fetch="none"
            )
            
            # Send push
            if admin["token"]:
                send_fcm_push(admin["token"], title, body)
                
    except Exception as e:
        logger.error(f"Failed to notify admins: {e}")
