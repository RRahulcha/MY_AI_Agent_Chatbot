import json
import logging
import time
import redis
from typing import Dict, Any
from fastapi import APIRouter, Request, HTTPException, status
from fastapi.responses import StreamingResponse
from pydantic import BaseModel
from app.config import settings
from app.agents.graph import agent_graph
from app.guardrails.detector import inspect_input_guardrail, inspect_output_guardrail

logger = logging.getLogger("ams-ai.api.chat")
router = APIRouter(prefix="/api/chat", tags=["AI Chatbot"])

# In-memory session store fallback when Redis is offline
IN_MEMORY_SESSIONS: Dict[str, Dict[str, Any]] = {}

# Test Redis connection once at startup
try:
    redis_client = redis.from_url(settings.REDIS_URL, decode_responses=True, protocol=2, socket_connect_timeout=0.2, socket_timeout=0.2)
    redis_client.ping()
    logger.info("Redis client connected successfully for AI Chat session memory.")
except Exception as e:
    logger.warning(f"Redis is unavailable ({e}). Using robust In-Memory session store.")
    redis_client = None

class ChatRequest(BaseModel):
    message: str

class ChatResponse(BaseModel):
    success: bool
    response: str
    confirmation_required: bool

def get_session(user_id: str) -> Dict[str, Any]:
    """Retrieve user session data (history + pending actions) from Redis or In-Memory."""
    if redis_client:
        try:
            raw = redis_client.get(f"ams_session:{user_id}")
            if raw:
                return json.loads(raw)
        except Exception as e:
            logger.error(f"Failed to read session from Redis: {e}")
            
    # Fallback to in-memory store
    sess = IN_MEMORY_SESSIONS.get(user_id)
    if sess:
        # 2-hour session expiry check
        if time.time() - sess.get("updated_at", 0) < 7200:
            return sess
        else:
            IN_MEMORY_SESSIONS.pop(user_id, None)
            
    return {"messages": [], "pending_action": None, "confirmation_required": False}

def save_session(user_id: str, session_data: Dict[str, Any]):
    """Persist session data (messages + pending state) to Redis or In-Memory."""
    session_data["updated_at"] = time.time()
    
    # Truncate messages to last 20 to preserve token budget
    if "messages" in session_data:
        session_data["messages"] = session_data["messages"][-20:]
        
    if redis_client:
        try:
            redis_client.setex(
                f"ams_session:{user_id}",
                7200,
                json.dumps(session_data)
            )
            return
        except Exception as e:
            logger.error(f"Failed to save session to Redis: {e}")
            
    # In-memory store
    IN_MEMORY_SESSIONS[user_id] = session_data

@router.post("", response_model=ChatResponse)
async def chat_endpoint(request: Request, body: ChatRequest):
    """Synchronous Chat API endpoint with Guardrail protection."""
    user = request.state.user
    user_id = user.user_id
    
    # 1. Load active session (history + pending confirmation state)
    session = get_session(user_id)
    history = session.get("messages", [])
    
    # Fast input guardrail check before graph execution
    guard_result = inspect_input_guardrail(body.message)
    if not guard_result.is_safe:
        logger.warning(f"Chat endpoint input rejected by guardrail: {guard_result.reason}")
        deflection = guard_result.deflection_message or "Request blocked by safety policy."
        
        # Save interaction to session history
        history.append({"role": "user", "content": body.message})
        history.append({"role": "assistant", "content": deflection})
        save_session(user_id, {
            "messages": history,
            "pending_action": session.get("pending_action"),
            "confirmation_required": session.get("confirmation_required", False)
        })
        
        return ChatResponse(
            success=True,
            response=deflection,
            confirmation_required=False
        )
    
    # Append the new user message
    new_user_message = {"role": "user", "content": body.message}
    history.append(new_user_message)
    
    # 2. Invoke LangGraph Orchestrator with state
    try:
        initial_state = {
            "messages": history,
            "user_context": {
                "user_id": user.user_id,
                "role": user.role,
                "designation": user.designation,
                "fullName": user.full_name,
                "email": user.email
            },
            "confirmation_required": session.get("confirmation_required", False),
            "pending_action": session.get("pending_action", None),
            "tool_calls": [],
            "tool_results": [],
            "retrieved_policies": []
        }
        
        result = agent_graph.invoke(initial_state)
        
        # 3. Store updated history and state
        updated_messages = result.get("messages", [])
        save_session(user_id, {
            "messages": updated_messages,
            "pending_action": result.get("pending_action", None),
            "confirmation_required": result.get("confirmation_required", False)
        })
        
        return ChatResponse(
            success=True,
            response=result.get("final_response", "I am unable to formulate an answer right now."),
            confirmation_required=result.get("confirmation_required", False)
        )
        
    except Exception as e:
        logger.error(f"Error in chat graph invocation: {e}")
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail=f"Orchestrator error: {str(e)}"
        )

@router.post("/stream")
async def chat_stream_endpoint(request: Request, body: ChatRequest):
    """
    Streaming Chat API endpoint with Guardrail protection.
    Streams token chunks back using Server-Sent Events (SSE).
    """
    user = request.state.user
    user_id = user.user_id
    
    async def sse_event_generator():
        session = get_session(user_id)
        history = session.get("messages", [])
        
        # 1. Fast Input Guardrail Check
        guard_result = inspect_input_guardrail(body.message)
        if not guard_result.is_safe:
            deflection = guard_result.deflection_message or "Request blocked by safety policy."
            history.append({"role": "user", "content": body.message})
            history.append({"role": "assistant", "content": deflection})
            save_session(user_id, {
                "messages": history,
                "pending_action": session.get("pending_action"),
                "confirmation_required": session.get("confirmation_required", False)
            })
            yield f"data: {json.dumps({'content': deflection, 'done': True})}\n\n"
            return
            
        history.append({"role": "user", "content": body.message})
        
        try:
            initial_state = {
                "messages": history,
                "user_context": {
                    "user_id": user.user_id,
                    "role": user.role,
                    "designation": user.designation,
                    "fullName": user.full_name
                },
                "confirmation_required": session.get("confirmation_required", False),
                "pending_action": session.get("pending_action", None),
                "tool_calls": [],
                "tool_results": [],
                "retrieved_policies": []
            }
            
            config = {"recursion_limit": 20}
            state = initial_state
            for output in agent_graph.stream(state, config):
                for node_name, updates in output.items():
                    state.update(updates)
                    
            # If deflection or confirmation required, stream the response instantly
            if state.get("final_response"):
                msg = state.get("final_response")
                yield f"data: {json.dumps({'content': msg, 'done': True})}\n\n"
                return
                
            # Stream response chunks from LLM
            from app.agents.graph import SYSTEM_PROMPT, get_llm_provider
            prompt = SYSTEM_PROMPT + "\n\n"
            prompt += f"Active User: {user.full_name} (ID: {user.user_id}, Role: {user.role}, Designation: {user.designation})\n\n"
            
            policies = state.get("retrieved_policies", [])
            if policies:
                prompt += "### Authoritative Policy Documents:\n"
                for doc in policies:
                    prompt += f"- Topic: {doc.get('topic')}\n  Content: {doc.get('content')}\n"
                prompt += "\n"
                
            results = state.get("tool_results", [])
            if results:
                prompt += "### Query/Action Tool Results:\n"
                for r in results:
                    prompt += f"- Status: {'Success' if r.get('success') else 'Failed'} | Data/Message: {r.get('data') or r.get('message')}\n"
                prompt += "\n"

            provider = get_llm_provider()
            recent_msgs = state["messages"][-8:] if len(state["messages"]) > 8 else state["messages"]
            formatted_msgs = [{"role": m["role"], "content": m["content"]} for m in recent_msgs]
            
            final_content = ""
            for chunk in provider.generate_stream(system_prompt=prompt, messages=formatted_msgs):
                chunk_text = chunk.get("content", "")
                final_content += chunk_text
                yield f"data: {json.dumps({'content': chunk_text, 'done': False})}\n\n"
                
            # Output guardrail check
            out_guard = inspect_output_guardrail(final_content)
            if not out_guard.is_safe:
                final_content = out_guard.deflection_message or "Response suppressed due to safety rules."
                
            state["messages"].append({"role": "assistant", "content": final_content})
            save_session(user_id, {
                "messages": state["messages"],
                "pending_action": state.get("pending_action"),
                "confirmation_required": state.get("confirmation_required", False)
            })
            
            yield f"data: {json.dumps({'content': '', 'done': True})}\n\n"
            
        except Exception as e:
            logger.error(f"Error in streaming endpoint: {e}")
            yield f"data: {json.dumps({'error': str(e), 'done': True})}\n\n"

    return StreamingResponse(sse_event_generator(), media_type="text/event-stream")
