from abc import ABC, abstractmethod
from typing import List, Dict, Any, Generator, Optional

class BaseLLMProvider(ABC):
    """
    Abstract interface for LLM provider wrappers.
    Ensures LangGraph/agent workflows are decoupled from specific LLM vendors (Gemini/OpenAI/Grok).
    """

    @abstractmethod
    def generate(
        self, 
        system_prompt: str, 
        messages: List[Dict[str, Any]], 
        tools: Optional[List[Any]] = None,
        response_format: Optional[Any] = None
    ) -> Dict[str, Any]:
        """
        Execute a synchronous completion request.
        
        Returns a dict containing:
            - "content": Text content or None
            - "tool_calls": List of tool call dicts (name, arguments, id) or None
            - "usage": Token usage stats (prompt_tokens, completion_tokens, total_tokens)
        """
        pass

    @abstractmethod
    def generate_stream(
        self, 
        system_prompt: str, 
        messages: List[Dict[str, Any]], 
        tools: Optional[List[Any]] = None
    ) -> Generator[Dict[str, Any], None, None]:
        """
        Execute a streaming completion request.
        
        Yields chunks containing:
            - "content": String text snippet
            - "finish_reason": Optional exit code (e.g. "stop", "tool_calls")
        """
        pass
