import logging
from qdrant_client import QdrantClient
from qdrant_client.http import models as rest_models
from app.config import settings

logger = logging.getLogger("ams-ai.rag.qdrant")

COLLECTION_NAME = "ams_policies"
VECTOR_SIZE = 3072  # Size of gemini-embedding-2 vectors

try:
    # Try connecting to external Qdrant container
    qdrant_client = QdrantClient(url=settings.QDRANT_URL, api_key=settings.QDRANT_API_KEY, timeout=3)
    # Check connection
    qdrant_client.get_collections()
    logger.info(f"Connected to Qdrant server at {settings.QDRANT_URL}")
except Exception as e:
    logger.warning(f"Could not connect to Qdrant server at {settings.QDRANT_URL}: {e}. Falling back to in-memory database.")
    # Fallback to sqlite-backed in-memory database for local test
    qdrant_client = QdrantClient(":memory:")

def ensure_collection():
    """Ensure the policy collection exists in Qdrant."""
    try:
        collections = qdrant_client.get_collections()
        exists = any(c.name == COLLECTION_NAME for c in collections.collections)
        if not exists:
            qdrant_client.create_collection(
                collection_name=COLLECTION_NAME,
                vectors_config=rest_models.VectorParams(
                    size=VECTOR_SIZE,
                    distance=rest_models.Distance.COSINE
                )
            )
            logger.info(f"Created Qdrant collection: {COLLECTION_NAME}")
    except Exception as e:
        logger.error(f"Error ensuring Qdrant collection exists: {e}")

# Run setup
ensure_collection()
