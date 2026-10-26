import os
from typing import Optional
from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict

class Settings(BaseSettings):
    # App Mode
    ENVIRONMENT: str = Field(default="development")
    PORT: int = Field(default=8000)
    HOST: str = Field(default="0.0.0.0")

    # LLM Settings
    LLM_PROVIDER: str = Field(default="groq")
    GROQ_API_KEY: str = Field(default="")
    GROQ_MODEL: str = Field(default="openai/gpt-oss-120b")
    GEMINI_API_KEY: str = Field(default="")

    # MySQL Settings
    MYSQL_HOST: str = Field(default="103.160.107.18")
    MYSQL_PORT: int = Field(default=3306)
    MYSQL_DATABASE: str = Field(default="nmrmlatur_settribe_ams")
    MYSQL_USER: str = Field(default="nmrmlatur_settribe_ams")
    MYSQL_PASSWORD: str = Field(default="SETTribe@ams1821")

    # Qdrant Vector DB Settings
    QDRANT_URL: str = Field(default="http://localhost:6333")
    QDRANT_API_KEY: Optional[str] = Field(default=None)

    # Redis Cache Settings
    REDIS_URL: str = Field(default="redis://localhost:6379/0")

    # Cloudflare R2 Storage Settings
    R2_ENDPOINT: Optional[str] = Field(default=None)
    R2_ACCESS_KEY: Optional[str] = Field(default=None)
    R2_SECRET_KEY: Optional[str] = Field(default=None)
    R2_BUCKET: str = Field(default="dev-ams-settribe")

    # Firebase FCM Settings
    FCM_PROJECT_ID: Optional[str] = Field(default="settribe-s-ams")
    FCM_SERVICE_ACCOUNT_JSON: Optional[str] = Field(default="../include/settribe-s-ams.json")

    # Security & Feature Flags
    AI_INTERNAL_SECRET: str = Field(default="")
    AI_CHATBOT_ENABLED: bool = Field(default=False)
    AI_WRITE_LEAVE_ENABLED: bool = Field(default=False)
    AI_WRITE_TASK_ENABLED: bool = Field(default=False)

    # Observability Settings
    LANGFUSE_HOST: str = Field(default="https://cloud.langfuse.com")
    LANGFUSE_PUBLIC_KEY: Optional[str] = Field(default=None)
    LANGFUSE_SECRET_KEY: Optional[str] = Field(default=None)
    SENTRY_DSN: Optional[str] = Field(default=None)

    # Load from .env file automatically (checking current dir, parent dir, or repo root)
    model_config = SettingsConfigDict(
        env_file=(
            os.path.join(os.path.dirname(os.path.dirname(__file__)), ".env"),
            os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), ".env"),
            ".env"
        ),
        env_file_encoding="utf-8",
        extra="ignore"
    )

settings = Settings()
