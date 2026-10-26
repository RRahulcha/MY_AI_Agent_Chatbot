import logging
from typing import List, Dict, Any
from qdrant_client.http import models as rest_models
from app.rag.qdrant import qdrant_client, COLLECTION_NAME
from app.rag.embeddings import embedding_model

logger = logging.getLogger("ams-ai.rag.retrieval")

def query_policy(query_text: str, user_role: str, top_k: int = 3) -> List[Dict[str, Any]]:
    """
    Search policy documents in Qdrant matching semantic query and user roles.
    """
    try:
        # 1. Generate query embedding
        query_vector = embedding_model.get_embedding(query_text)
        
        # 2. Setup metadata filter (role-aware retrieval)
        # Asserts the document has the user's role listed in its 'roles' array
        role_filter = rest_models.Filter(
            should=[
                rest_models.FieldCondition(
                    key="roles",
                    match=rest_models.MatchValue(value=user_role)
                ),
                rest_models.FieldCondition(
                    key="roles",
                    match=rest_models.MatchValue(value="all")
                )
            ]
        )

        # 3. Perform vector search (supporting both modern query_points and search APIs)
        search_results = []
        if hasattr(qdrant_client, "query_points"):
            response = qdrant_client.query_points(
                collection_name=COLLECTION_NAME,
                query=query_vector,
                query_filter=role_filter,
                limit=top_k
            )
            search_results = response.points if hasattr(response, "points") else response
        elif hasattr(qdrant_client, "search"):
            search_results = qdrant_client.search(
                collection_name=COLLECTION_NAME,
                query_vector=query_vector,
                query_filter=role_filter,
                limit=top_k
            )

        results = []
        for hit in search_results:
            payload = hit.payload or {}
            results.append({
                "topic": payload.get("topic"),
                "content": payload.get("content"),
                "version": payload.get("version"),
                "score": getattr(hit, "score", 0.0)
            })
            
        return results

    except Exception as e:
        logger.error(f"Error querying policy collection: {e}")
        raise e
