import logging
from typing import List, Dict, Any, Generator, Optional
from google import genai
from google.genai import types
from app.llm.base import BaseLLMProvider
from app.config import settings

logger = logging.getLogger("ams-ai.llm.gemini")

class GeminiProvider(BaseLLMProvider):
    def __init__(self):
        if not settings.GEMINI_API_KEY:
            raise ValueError("GEMINI_API_KEY is not configured in environment settings.")
        self.client = genai.Client(api_key=settings.GEMINI_API_KEY)
        self.model_name = "gemini-2.5-flash"  # Standard high-speed, cost-effective Gemini model

    def _convert_messages(self, messages: List[Dict[str, Any]]) -> List[types.Content]:
        """Convert standard role/content dicts into google-genai Content structures."""
        converted = []
        for msg in messages:
            role = "user" if msg["role"] in ["user", "system"] else "model"
            parts = []
            
            # Text content
            if "content" in msg and msg["content"]:
                parts.append(types.Part.from_text(text=msg["content"]))
                
            # Function calls (from model)
            if "tool_calls" in msg and msg["tool_calls"]:
                for tc in msg["tool_calls"]:
                    parts.append(types.Part.from_function_call(
                        name=tc["name"],
                        args=tc["arguments"]
                    ))
                    
            # Function response (from user/tool node)
            if msg["role"] == "tool":
                parts.append(types.Part.from_function_response(
                    name=msg["name"],
                    response={"result": msg["content"]}
                ))
                
            if parts:
                converted.append(types.Content(role=role, parts=parts))
        return converted

    def generate(
        self, 
        system_prompt: str, 
        messages: List[Dict[str, Any]], 
        tools: Optional[List[Any]] = None,
        response_format: Optional[Any] = None
    ) -> Dict[str, Any]:
        try:
            contents = self._convert_messages(messages)
            
            # Map tools if specified
            gemini_tools = []
            if tools:
                # google-genai SDK allows passing Python functions directly or pre-built tool structures
                gemini_tools = [types.Tool(function_declarations=tools)]

            config = types.GenerateContentConfig(
                system_instruction=system_prompt,
                tools=gemini_tools if tools else None,
                response_mime_type="application/json" if response_format else None,
            )

            response = self.client.models.generate_content(
                model=self.model_name,
                contents=contents,
                config=config
            )

            # Parse tool calls
            tool_calls = []
            if response.function_calls:
                for fc in response.function_calls:
                    tool_calls.append({
                        "id": fc.name, # Use name as ID for tracking
                        "name": fc.name,
                        "arguments": fc.args
                    })

            # Calculate token usage
            usage = {
                "prompt_tokens": response.usage_metadata.prompt_token_count if response.usage_metadata else 0,
                "completion_tokens": response.usage_metadata.candidates_token_count if response.usage_metadata else 0,
                "total_tokens": response.usage_metadata.total_token_count if response.usage_metadata else 0
            }

            return {
                "content": response.text if response.text else None,
                "tool_calls": tool_calls if tool_calls else None,
                "usage": usage
            }

        except Exception as e:
            logger.error(f"Gemini API completion execution failed: {e}")
            raise e

    def generate_stream(
        self, 
        system_prompt: str, 
        messages: List[Dict[str, Any]], 
        tools: Optional[List[Any]] = None
    ) -> Generator[Dict[str, Any], None, None]:
        try:
            contents = self._convert_messages(messages)
            gemini_tools = []
            if tools:
                gemini_tools = [types.Tool(function_declarations=tools)]

            config = types.GenerateContentConfig(
                system_instruction=system_prompt,
                tools=gemini_tools if tools else None
            )

            response_stream = self.client.models.generate_content_stream(
                model=self.model_name,
                contents=contents,
                config=config
            )

            for chunk in response_stream:
                content_chunk = chunk.text if chunk.text else ""
                finish_reason = None
                
                # Check for function calls in stream
                if chunk.function_calls:
                    finish_reason = "tool_calls"
                    
                yield {
                    "content": content_chunk,
                    "finish_reason": finish_reason
                }

        except Exception as e:
            logger.error(f"Gemini API stream generation failed: {e}")
            raise e
