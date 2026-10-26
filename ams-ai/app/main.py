import os
import uvicorn
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles

from app.config import settings
from app.monitoring.telemetry import setup_json_logging
from app.monitoring.sentry import init_sentry
from app.api.middleware import SessionAuthMiddleware
from app.api.chat import router as chat_router
from app.api.health import router as health_router

# 1. Setup structured logging and error tracking before app boot
setup_json_logging()
init_sentry()

# 2. Initialize FastAPI
app = FastAPI(
    title="SETTribe AMS Agentic AI Chatbot API",
    description="Isolated microservice hosting the LangGraph agent orchestrator, tools, and RAG search for AMS.",
    version="1.0.0"
)

# 3. Add CORS Middleware (allowing calls from existing PHP web app domain)
app.add_middleware(
    CORSMiddleware,
    allow_origins=[
        "http://127.0.0.1:8080",
        "http://localhost:8080",
    ],
    allow_credentials=False,
    allow_methods=["GET", "POST", "OPTIONS"],
    allow_headers=["Content-Type", "x-user-id", "x-user-role", "x-user-designation"],
)

# 4. Add Custom Session & RBAC Auth Middleware
app.add_middleware(SessionAuthMiddleware)

# 5. Include API Routers
app.include_router(chat_router)
app.include_router(health_router)

@app.get("/")
def root():
    return {
        "service": "SETTribe AMS AI Assistant Microservice",
        "status": "online",
        "documentation": "/docs",
        "message": "This is the backend AI microservice. Open the main AMS Web Portal (e.g. http://localhost/Dev-SETTribe-AMS-tusharsingh/pages/adminDashboard.php) to interact with the Chatbot UI."
    }

# 6. Mount local uploads directory static folder to serve files in simulation mode
UPLOAD_DIR = os.path.abspath(os.path.join(os.path.dirname(os.path.dirname(__file__)), "uploads"))
if not os.path.exists(UPLOAD_DIR):
    os.makedirs(UPLOAD_DIR, exist_ok=True)
app.mount("/uploads", StaticFiles(directory=UPLOAD_DIR), name="uploads")

if __name__ == "__main__":
    uvicorn.run("app.main:app", host=settings.HOST, port=settings.PORT, reload=False)
