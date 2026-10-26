import logging
from app.llm.base import BaseLLMProvider
from app.llm.groq import GroqProvider

logger = logging.getLogger("ams-ai.llm")

_PROVIDER_INSTANCE = None

def get_llm_provider() -> BaseLLMProvider:
    """Acquire the unified Groq LLM provider instance (cached singleton)."""
    global _PROVIDER_INSTANCE
    if _PROVIDER_INSTANCE is None:
        logger.info("Initializing unified LLM Provider: Groq (GroqCloud LPU)")
        _PROVIDER_INSTANCE = GroqProvider()
    return _PROVIDER_INSTANCE
