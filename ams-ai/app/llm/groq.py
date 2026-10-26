import logging
from typing import List, Dict, Any, Generator, Optional
from groq import Groq
from app.llm.base import BaseLLMProvider
from app.config import settings

logger = logging.getLogger("ams-ai.llm.groq")

class GroqProvider(BaseLLMProvider):
    """
    GroqCloud LLM Provider implementation.
    Delivers lightning-fast inference for LangGraph agents using LLaMA-3.3 on Groq LPUs.
    """

    def __init__(self):
        if not settings.GROQ_API_KEY:
            raise ValueError("GROQ_API_KEY is not configured in environment settings.")
        self.client = Groq(api_key=settings.GROQ_API_KEY)
        
        # Priority order of reliable, fast chat & structured output models
        preferred_model = settings.GROQ_MODEL or "openai/gpt-oss-20b"
        self.model_name = preferred_model
        logger.info(f"GroqProvider active model: {self.model_name}")

    def _convert_messages(self, system_prompt: str, messages: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
        """Format standard messages for Groq OpenAI-compatible chat completions."""
        formatted = []
        
        # Add system prompt as the first message
        if system_prompt:
            formatted.append({"role": "system", "content": system_prompt})
            
        for msg in messages:
            role = msg.get("role", "user")
            # Map role names to OpenAI/Groq standards
            if role in ["user", "human"]:
                role = "user"
            elif role in ["assistant", "bot", "model"]:
                role = "assistant"
            elif role == "system":
                role = "system"
            elif role == "tool":
                role = "tool"
                
            content = msg.get("content") or ""
            entry = {"role": role, "content": content}
            
            if "tool_calls" in msg and msg["tool_calls"]:
                entry["tool_calls"] = msg["tool_calls"]
                
            formatted.append(entry)
            
        return formatted

    def generate(
        self, 
        system_prompt: str, 
        messages: List[Dict[str, Any]], 
        tools: Optional[List[Any]] = None,
        response_format: Optional[Any] = None
    ) -> Dict[str, Any]:
        try:
            formatted_messages = self._convert_messages(system_prompt, messages)
            
            kwargs: Dict[str, Any] = {
                "model": self.model_name,
                "messages": formatted_messages,
                "temperature": 0.2,
            }
            
            # JSON format mode
            if response_format in ["json", {"type": "json_object"}]:
                kwargs["response_format"] = {"type": "json_object"}
                
            # Tool declarations
            if tools:
                kwargs["tools"] = tools
                
            response = self.client.chat.completions.create(**kwargs)
            choice = response.choices[0]
            message = choice.message
            
            # Parse tool calls if returned
            tool_calls = []
            if hasattr(message, "tool_calls") and message.tool_calls:
                for tc in message.tool_calls:
                    tool_calls.append({
                        "id": tc.id,
                        "name": tc.function.name,
                        "arguments": tc.function.arguments
                    })
                    
            usage = {
                "prompt_tokens": response.usage.prompt_tokens if response.usage else 0,
                "completion_tokens": response.usage.completion_tokens if response.usage else 0,
                "total_tokens": response.usage.total_tokens if response.usage else 0
            }
            
            return {
                "content": message.content or "",
                "tool_calls": tool_calls,
                "usage": usage
            }
            
        except Exception as e:
            logger.error(f"Groq API completion execution failed: {e}")
            raise e

    def generate_stream(
        self, 
        system_prompt: str, 
        messages: List[Dict[str, Any]], 
        tools: Optional[List[Any]] = None
    ) -> Generator[Dict[str, Any], None, None]:
        try:
            formatted_messages = self._convert_messages(system_prompt, messages)
            
            kwargs: Dict[str, Any] = {
                "model": self.model_name,
                "messages": formatted_messages,
                "stream": True,
                "temperature": 0.2,
            }
            
            if tools:
                kwargs["tools"] = tools
                
            response_stream = self.client.chat.completions.create(**kwargs)
            
            for chunk in response_stream:
                if not chunk.choices:
                    continue
                delta = chunk.choices[0].delta
                content_chunk = delta.content if delta and delta.content else ""
                finish_reason = chunk.choices[0].finish_reason
                
                yield {
                    "content": content_chunk,
                    "finish_reason": finish_reason
                }
                
        except Exception as e:
            logger.error(f"Groq API stream generation failed: {e}")
            raise e
