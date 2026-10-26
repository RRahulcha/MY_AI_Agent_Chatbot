import logging
from app.config import settings

logger = logging.getLogger("ams-ai.monitoring.langfuse")

class DummyLangfuse:
    """Mock Langfuse client for local development when keys are omitted."""
    def trace(self, *args, **kwargs):
        return DummyTrace()

class DummyTrace:
    def span(self, *args, **kwargs):
        return DummySpan()
    def event(self, *args, **kwargs):
        pass

class DummySpan:
    def end(self, *args, **kwargs):
        pass

# Initialize client
if settings.LANGFUSE_PUBLIC_KEY and settings.LANGFUSE_SECRET_KEY:
    try:
        from langfuse import Langfuse
        langfuse_client = Langfuse(
            public_key=settings.LANGFUSE_PUBLIC_KEY,
            secret_key=settings.LANGFUSE_SECRET_KEY,
            host=settings.LANGFUSE_HOST
        )
        logger.info("Langfuse LLM observability client initialized successfully.")
    except ImportError:
        logger.info("langfuse package not installed. Observability running in offline mode.")
        langfuse_client = DummyLangfuse()
    except Exception as e:
        logger.error(f"Langfuse client initialization failed: {e}. Falling back to dummy.")
        langfuse_client = DummyLangfuse()
else:
    logger.info("Langfuse keys missing. Observability running in offline simulation mode.")
    langfuse_client = DummyLangfuse()
