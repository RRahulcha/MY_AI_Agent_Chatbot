import re
import os
import logging
from dataclasses import dataclass
from typing import Optional, List

groq_api_key = os.getenv("GROQ_API_KEY")
logger = logging.getLogger("ams-ai.guardrails")

@dataclass
class GuardrailResult:
    is_safe: bool
    category: Optional[str] = None  # "injection", "jailbreak", "off_topic", "credential_leak", "system_leak"
    reason: Optional[str] = None
    deflection_message: Optional[str] = None


# Standard deflection responses
DEFLECTION_PROMPT_INJECTION = (
    "**Security Notice**: I cannot process instruction override requests, system prompt extractions, "
    "or simulated personas. I am strictly dedicated to assisting with **SETTribe AMS** (Attendance, Leaves, Tasks, and Policies)."
)

DEFLECTION_OFF_TOPIC = (
    "I am the **SETTribe AMS AI Assistant**, specialized exclusively in **Attendance, Leave Management, Tasks, and Company HR Policies**.\n\n"
    "I cannot assist with general programming, code generation, homework, or external non-AMS topics.\n\n"
    "Here are a few things I can help you with:\n"
    "- **Check Leave Balances** (*'What is my CL and SL balance?'*)\n"
    "- **Apply for Leaves** (*'Apply for 1 day Casual Leave tomorrow'*)\n"
    "- **Attendance & Punch Info** (*'Show my attendance for this week'*)\n"
    "- **Task Tracking** (*'Log a new task for Project X'*)\n"
    "- **Company Policies** (*'What is the late mark policy?'*)"
)

# 1. Prompt Injection & Jailbreak Patterns
INJECTION_PATTERNS: List[re.Pattern] = [
    # Instruction overrides
    re.compile(r"ignore\s+(all\s+)?(previous|prior|above|existing|system)\s+(instructions|prompts|rules|commands|constraints|directives)", re.IGNORECASE),
    re.compile(r"disregard\s+(all\s+)?(previous|prior|above|existing)\s+(instructions|prompts|rules)", re.IGNORECASE),
    re.compile(r"forget\s+(all\s+)?(previous|prior|your)\s+(instructions|rules|identity)", re.IGNORECASE),
    re.compile(r"override\s+(all\s+)?(instructions|rules|system\s+prompts|safety|guardrails)", re.IGNORECASE),
    re.compile(r"bypass\s+(all\s+)?(filters|guardrails|safety|security|rules)", re.IGNORECASE),
    
    # Jailbreak personas & modes
    re.compile(r"\b(dan\s+mode|jailbreak|jailbroken|unrestricted\s+mode|dev\s+mode|developer\s+mode|god\s+mode)\b", re.IGNORECASE),
    re.compile(r"you\s+are\s+now\s+(in\s+)?(an?\s+)?(unrestricted|uncensored|developer|evil|opposite|debug)\s+mode", re.IGNORECASE),
    re.compile(r"act\s+as\s+(an?\s+)?(unrestricted|uncensored|evil|hacker|python\s+developer|general\s+ai|chatgpt|gpt-4|linux\s+terminal)", re.IGNORECASE),
    re.compile(r"pretend\s+(to\s+be|you\s+are)\s+(an?\s+)?(unrestricted|uncensored|different|another|evil|developer)", re.IGNORECASE),
    re.compile(r"simulate\s+(an?\s+)?(unrestricted|linux\s+terminal|virtual\s+machine|bash\s+shell)", re.IGNORECASE),
    re.compile(r"from\s+now\s+on\s*,\s*(you\s+will|you\s+must|act\s+as|pretend)", re.IGNORECASE),
    
    # System prompt extraction
    re.compile(r"(show|tell|reveal|display|output|print|give)\s+(me\s+)?(your\s+)?(exact\s+)?(system\s+prompt|initial\s+prompt|hidden\s+instructions|system\s+instructions|core\s+prompt)", re.IGNORECASE),
    re.compile(r"what\s+(is|are)\s+(your\s+)?(system\s+prompt|initial\s+instructions|hidden\s+rules|developer\s+instructions)", re.IGNORECASE),
    re.compile(r"repeat\s+(everything|the\s+words|the\s+text)\s+(above|before|from\s+the\s+beginning)", re.IGNORECASE),
    
    # Credential & internal exfiltration
    re.compile(r"(print|reveal|give|show|dump|leak)\s+(the\s+)?(mysql|database|db|redis|qdrant|gemini|groq|api_key|password|secret|env|environment\s+variables)", re.IGNORECASE),
    re.compile(r"\b(SELECT\s+.*\s+FROM\s+information_schema|DROP\s+TABLE|UNION\s+SELECT\s+password)\b", re.IGNORECASE),
]

# 2. General Code Generation & Off-Topic Coding Patterns (Non-AMS)
OFF_TOPIC_CODING_PATTERNS: List[re.Pattern] = [
    re.compile(r"\b(code\s+to\s+reverse|reverse\s+.*(array|string|list|linked\s*list))\b", re.IGNORECASE),
    re.compile(r"\b(write|create|generate|build|implement|give|show)\s+(me\s+)?(a\s+)?.*(script|function|program|code|class|snippet|landing\s*page|website|webpage|calculator|app|component|leetcode|algorithm)\b", re.IGNORECASE),
    re.compile(r"\b(how\s+to\s+(write|code|implement|create|build)\s+.*(binary\s+search|sorting|fibonacci|factorial|linked\s*list|tree|graph|dp|leetcode|scraper|api))\b", re.IGNORECASE),
    re.compile(r"\b(solve\s+.*(leetcode|codeforces|hackerrank|coding\s+problem|math\s+equation))\b", re.IGNORECASE),
    re.compile(r"\b(explain\s+.*(recursion|pointers|quicksort|mergesort|react\s+hooks|async/await|polymorphism))\b", re.IGNORECASE),
    re.compile(r"\b(in\s+(python|java|javascript|c\+\+|golang|rust|php|sql|bash|c#|typescript)\b.*\b(code|function|class|program))\b", re.IGNORECASE),
    re.compile(r"\b(html|css|tailwind|bootstrap|react|vue|angular)\b.*\b(landing\s*page|website|template|webpage)\b", re.IGNORECASE),
]

# 3. Non-AMS General Queries (Essays, Poems, Recipes, Trivia)
OFF_TOPIC_GENERAL_PATTERNS: List[re.Pattern] = [
    re.compile(r"\b(write|generate)\s+.*(poem|song|story|essay|joke|speech|love\s+letter)\b", re.IGNORECASE),
    re.compile(r"\bhow\s+to\s+(make|cook|bake|brew|prepare)\s+.*(cake|pizza|biryani|pasta|coffee|tea|recipe|dish|food|bomb|drug|weapon|malware|virus)\b", re.IGNORECASE),
    re.compile(r"\b(who\s+is|what\s+is\s+the\s+capital\s+of|tell\s+me\s+about\s+.*(world\s+war|history\s+of|universe|einstein|quantum|space\s*x|president|prime\s+minister))\b", re.IGNORECASE),
    re.compile(r"\btranslate\b.*?\b(to|into)\b.*?\b(spanish|french|german|hindi|marathi|japanese|chinese|russian|english|italian)\b", re.IGNORECASE),
    re.compile(r"\btranslate\s+(this|the|into|from)\b", re.IGNORECASE),
]

# Whitelist keywords with word boundaries
AMS_WHITELIST_REGEX = re.compile(
    r"\b(leave|leaves|attendance|punch|punched|clock|task|tasks|project|roster|ro|cl|sl|pl|td|"
    r"early\s+go|late\s+mark|policy|intern|internship|employee|settribe|ams|balance|"
    r"work\s+hours|timing|holiday|saturday|sunday|permission|half\s+day)\b",
    re.IGNORECASE
)


def contains_ams_context(text: str) -> bool:
    """Check if the text refers to authentic AMS domain entities using word boundaries."""
    return bool(AMS_WHITELIST_REGEX.search(text))


def inspect_input_guardrail(text: str) -> GuardrailResult:
    """
    Screens incoming user prompts against prompt injection, jailbreaks, 
    and off-topic non-AMS requests.
    """
    if not text or not isinstance(text, str):
        return GuardrailResult(is_safe=True)

    cleaned = text.strip()
    
    # 1. Check for prompt injection / jailbreaks (Highest priority - Whitelist does not bypass this)
    for pattern in INJECTION_PATTERNS:
        match = pattern.search(cleaned)
        if match:
            logger.warning(f"Guardrail triggered: Prompt Injection pattern matched '{match.group(0)}'")
            return GuardrailResult(
                is_safe=False,
                category="injection",
                reason=f"Matched injection pattern: {match.group(0)}",
                deflection_message=DEFLECTION_PROMPT_INJECTION
            )

    # 2. Check for off-topic coding requests
    for pattern in OFF_TOPIC_CODING_PATTERNS:
        match = pattern.search(cleaned)
        if match:
            # If user explicitly mentions legitimate AMS action (e.g. log task "create landing page")
            if contains_ams_context(cleaned) and any(w in cleaned.lower() for w in ["log", "task", "project", "duration"]):
                continue
            logger.info(f"Guardrail triggered: Off-topic coding pattern matched '{match.group(0)}'")
            return GuardrailResult(
                is_safe=False,
                category="off_topic",
                reason=f"Matched off-topic coding pattern: {match.group(0)}",
                deflection_message=DEFLECTION_OFF_TOPIC
            )

    # 3. Check for off-topic general queries
    for pattern in OFF_TOPIC_GENERAL_PATTERNS:
        match = pattern.search(cleaned)
        if match:
            if not contains_ams_context(cleaned):
                logger.info(f"Guardrail triggered: Off-topic general pattern matched '{match.group(0)}'")
                return GuardrailResult(
                    is_safe=False,
                    category="off_topic",
                    reason=f"Matched off-topic general pattern: {match.group(0)}",
                    deflection_message=DEFLECTION_OFF_TOPIC
                )

    return GuardrailResult(is_safe=True)


def inspect_output_guardrail(text: str) -> GuardrailResult:
    """
    Inspects model output before sending to the user to prevent data exfiltration
    or leakage of internal secrets, environment variables, or rogue code blocks.
    """
    if not text or not isinstance(text, str):
        return GuardrailResult(is_safe=True)

    # Detect credential leaks
    sensitive_tokens = [
        "SETTribe@ams1821",
        "AIzaSyD-5O-sHvEQeZGJ65hU5oTbEcxY-P1FVBk",
        "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",
        "nmrmlatur_settribe_ams",
        "MYSQL_PASSWORD",
        "GROQ_API_KEY",
        "GEMINI_API_KEY",
    ]

    for token in sensitive_tokens:
        if token in text:
            logger.critical(f"Output Guardrail triggered: Model attempted to leak sensitive token '{token[:6]}...'")
            return GuardrailResult(
                is_safe=False,
                category="credential_leak",
                reason="Sensitive token detected in model output.",
                deflection_message="I have processed your request, but the output was suppressed due to privacy and safety policies."
            )

    # Check for general Python/JS code blocks that should never be produced by AMS-AI
    code_block_patterns = [
        re.compile(r"```python\s*(def\s+reverse|def\s+sort|def\s+fibonacci|import\s+numpy|import\s+pandas)", re.IGNORECASE),
        re.compile(r"```(javascript|java|cpp|c\+\+)\s*(function\s+reverse|public\s+static\s+void\s+main)", re.IGNORECASE),
    ]

    for pat in code_block_patterns:
        if pat.search(text):
            logger.warning("Output Guardrail triggered: Model generated off-topic code block.")
            return GuardrailResult(
                is_safe=False,
                category="off_topic",
                reason="Model generated off-topic programming code block.",
                deflection_message=DEFLECTION_OFF_TOPIC
            )

    return GuardrailResult(is_safe=True)


def get_guardrail_deflection_message(reason: str = "off_topic") -> str:
    """Helper to get appropriate deflection response."""
    if reason in ["injection", "jailbreak", "system_leak"]:
        return DEFLECTION_PROMPT_INJECTION
    return DEFLECTION_OFF_TOPIC
