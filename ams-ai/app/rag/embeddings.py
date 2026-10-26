import logging
from typing import List
from google import genai
from app.config import settings

logger = logging.getLogger("ams-ai.rag.embeddings")

class EmbeddingModel:
    def __init__(self):
        self._client = None
        self.model_name = "gemini-embedding-2"  # Google's standard embedding model

    @property
    def client(self):
        if self._client is None:
            if not settings.GEMINI_API_KEY:
                raise ValueError("GEMINI_API_KEY is not configured.")
            self._client = genai.Client(api_key=settings.GEMINI_API_KEY)
        return self._client

    def get_embedding(self, text: str) -> List[float]:
        """Generate embedding vector for a single text string."""
        try:
            response = self.client.models.embed_content(
                model=self.model_name,
                contents=text
            )
            # Check response structure
            if response.embeddings:
                return response.embeddings[0].values
            elif response.embedding:
                return response.embedding.values
            else:
                raise ValueError("No embeddings returned by API.")
        except Exception as e:
            logger.error(f"Failed to generate embedding: {e}")
            raise e

    def get_embeddings(self, texts: List[str]) -> List[List[float]]:
        """Generate embedding vectors for multiple text strings."""
        try:
            response = self.client.models.embed_content(
                model=self.model_name,
                contents=texts
            )
            if response.embeddings:
                return [emb.values for emb in response.embeddings]
            else:
                raise ValueError("No embeddings returned by API.")
        except Exception as e:
            logger.error(f"Failed to generate batch embeddings: {e}")
            raise e

# Singleton instance
embedding_model = EmbeddingModel()
