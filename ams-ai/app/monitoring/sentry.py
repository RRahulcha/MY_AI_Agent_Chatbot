import logging
from app.config import settings

logger = logging.getLogger("ams-ai.monitoring.sentry")

def init_sentry():
    """Initialize Sentry SDK with custom context settings if available and configured."""
    if not settings.SENTRY_DSN:
        logger.info("Sentry DSN not configured. Sentry tracking is disabled.")
        return

    try:
        import sentry_sdk
        from sentry_sdk.integrations.fastapi import FastApiIntegration

        sentry_sdk.init(
            dsn=settings.SENTRY_DSN,
            environment=settings.ENVIRONMENT,
            traces_sample_rate=1.0 if settings.ENVIRONMENT == "development" else 0.1,
            integrations=[FastApiIntegration()]
        )
        logger.info("Sentry SDK successfully initialized.")
    except ImportError:
        logger.info("sentry-sdk not installed; error monitoring running in offline mode.")
    except Exception as e:
        logger.error(f"Sentry SDK initialization failed: {e}")
