import operator
from typing import TypedDict, Annotated, Sequence, Dict, Any, List, Optional

def append_messages(left: list, right: list) -> list:
    """Reducer function to append new messages to conversation state."""
    # Ensure role, content, name etc. are preserved cleanly
    return left + right

class AgentState(TypedDict):
    # Conversational history
    messages: Annotated[list, append_messages]
    
    # Session context of the authenticated caller
    user_context: Dict[str, Any]
    
    # Flow indicators
    next_node: str
    intent: Optional[str]
    
    # RAG policy context retrieved from vector db
    retrieved_policies: List[Dict[str, Any]]
    
    # Pending action requiring user validation/confirmation
    confirmation_required: bool
    pending_action: Optional[Dict[str, Any]]
    
    # Track executed tool parameters and final results
    tool_calls: List[Dict[str, Any]]
    tool_results: List[Dict[str, Any]]
    
    # Final generated response
    final_response: Optional[str]
