import os
import json
import logging
import datetime
from langgraph.graph import StateGraph, END
from app.agents.state import AgentState
from app.llm import get_llm_provider
from app.auth.permissions import has_permission
from app.guardrails.detector import (
    inspect_input_guardrail,
    inspect_output_guardrail,
    get_guardrail_deflection_message,
    DEFLECTION_OFF_TOPIC,
    DEFLECTION_PROMPT_INJECTION,
    contains_ams_context
)

# Import tools dynamically or explicitly
from app.tools.leave import get_leave_balances, apply_leave, cancel_leave, get_pending_leaves, approve_leave, reject_leave
from app.tools.wfh import apply_wfh, get_wfh_status
from app.tools.attendance import get_attendance_range, punch_in, punch_out, check_punch_out_status, get_today_punch_status
from app.tools.tasks import log_task, pause_task, resume_task, finish_task, get_my_tasks
from app.rag.retrieval import query_policy

logger = logging.getLogger("ams-ai.agents.graph")

# Load prompts
PROMPTS_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "prompts")

def read_prompt(filename: str) -> str:
    path = os.path.join(PROMPTS_DIR, filename)
    if os.path.exists(path):
        with open(path, "r", encoding="utf-8") as f:
            return f.read()
    return ""

SYSTEM_PROMPT = read_prompt("system_v1.txt")
ROUTER_PROMPT = read_prompt("router_v1.txt")

def normalize_date_year(date_str: str) -> str:
    """Ensure dates without explicit past years use the current year."""
    if not date_str or not isinstance(date_str, str):
        return date_str
    parts = date_str.strip().split("-")
    cur_year = datetime.date.today().year
    if len(parts) == 3 and len(parts[0]) == 4:
        try:
            y = int(parts[0])
            if y < cur_year:
                return f"{cur_year}-{parts[1]}-{parts[2]}"
        except ValueError:
            pass
    return date_str

FALLBACK_POLICIES = [
    {
        "topic": "Work From Home Application Procedure",
        "content": (
            "How to Apply for Work From Home (WFH) in SETTribe AMS:\n"
            "1. Through AI Chatbot: Ask to apply for WFH and provide the start date, end date, and reason. The assistant will summarize the WFH request and ask for confirmation before submitting it. WFH is a separate request, not a leave type.\n"
            "2. Through Portal Web Interface: Navigate to WFH -> Apply WFH, enter the date range and reason, select an approver, then submit. The request remains pending until approved.\n"
            "WFH requests cannot overlap with active leave or another WFH request."
        ),
        "keywords": ["wfh", "work from home", "remote work"]
    },
    {
        "topic": "Leave Application Procedure",
        "content": (
            "How to Apply for Leave in SETTribe AMS:\n"
            "1. Through AI Chatbot: You can directly say 'Apply for 1 day CL on YYYY-MM-DD due to [reason]' or 'Apply for leave tomorrow'. The bot will verify your balance and prompt for confirmation.\n"
            "2. Through Portal Web Interface:\n"
            "   - Navigate to 'Leave' -> 'Apply for Leave' from the sidebar.\n"
            "   - Select Leave Type: CL (Casual Leave), SL (Sick Leave), PL (Privilege Leave), TD (Tour Day), or RO (Rostering Off).\n"
            "   - Select Start Date and End Date (Sundays and past dates are blocked).\n"
            "   - Enter Reason for leave. (If Sick Leave exceeds 1 day, medical certificate upload is required).\n"
            "   - Click Submit Application. Your reporting manager or HR will review and approve it."
        ),
        "keywords": ["apply", "application", "procedure", "process", "steps", "how to apply", "how do i apply", "leave apply"]
    },
    {
        "topic": "Late Mark & Office Timing Policy",
        "content": (
            "Working Hours: 9:30 AM to 6:30 PM. Grace Time: 15 minutes.\n"
            "Arrival up to 9:45 AM: Marked present and on-time (lateMark = 'no').\n"
            "Arrival between 9:46 AM and 10:30 AM: Present but receives 1 Late Mark (lateMark = 'yes').\n"
            "Arrival after 10:30 AM: Marked as Half Day.\n"
            "Monthly Late Mark Penalty:\n"
            "- 1 to 3 Late Marks in a calendar month: Warning level (0 leave deduction).\n"
            "- 4th Late Mark onwards: Half Day (0.5 day) leave deduction for every 2 late marks.\n"
            "Formula: floor((lateCount - 2) / 2) * 0.5 days deduction."
        ),
        "keywords": ["late", "late mark", "grace", "timing", "hours", "late policy", "penalty"]
    },
    {
        "topic": "Early Go Policy",
        "content": (
            "Leaving before standard office exit hour (6:30 PM) without approval constitutes Early Go.\n"
            "- Departure up to 15 minutes early: Allowed occasionally (0 mark).\n"
            "- Departure between 15 to 120 minutes early: Receives 1 Early Go Mark.\n"
            "- Departure more than 120 minutes early: Evaluated as Half Day.\n"
            "Approved early departures (hr_granted = 1) do NOT count toward the monthly early departure penalty mark counter."
        ),
        "keywords": ["early", "early go", "departure", "leave early", "exit"]
    },
    {
        "topic": "Short Leave Policy",
        "content": (
            "Employees can take short leaves for bank, hospital, or government office visits.\n"
            "- Free quota: 2 Short Leaves per month, maximum of 2 hours duration each.\n"
            "- Unused short leaves do NOT carry forward to the next month.\n"
            "- Additional short leaves beyond 2 cost 0.5 days leave deduction."
        ),
        "keywords": ["short leave", "short", "hospital", "bank"]
    },
    {
        "topic": "Rostering Off (RO) Policy",
        "content": (
            "Rostering Off (RO) is a Saturday-only leave auto-approved by standard rules.\n"
            "- Maximum RO limit: 2 days per calendar month for offline interns.\n"
            "- RO leaves must strictly fall on Saturdays (no other days allowed)."
        ),
        "keywords": ["roster", "rostering", "ro", "saturday"]
    },
    {
        "topic": "General Leave and WFH Policies",
        "content": (
            "Available leaves: CL (Casual Leave), SL (Sick Leave), PL (Privilege Leave), TD (Tour Day), RO (Rostering Off).\n"
            "- Leave applications must be submitted in advance. Passed dates are blocked.\n"
            "- Sundays cannot be selected as start or end date of leave.\n"
            "- Sick Leave (SL) requires uploading medical documentation if it exceeds 1 day.\n"
            "- Overlapping leaves or WFH requests for the same dates are strictly blocked."
        ),
        "keywords": ["general", "leave policy", "casual leave", "sick leave", "rules", "leave rules", "cl", "sl", "pl"]
    }
]

# Define graph nodes
def router_node(state: AgentState) -> dict:
    """Classifies user intent, enforces input guardrails, and extracts parameters."""
    logger.info("Entering Router Node")
    messages = state["messages"]
    if not messages:
        return {"intent": "chat", "next_node": "llm_generator_node", "tool_calls": []}

    last_msg = messages[-1]
    last_msg_content = last_msg.get("content", "").strip()
    last_msg_lower = last_msg_content.lower()
    
    # 1. Immediate Input Guardrail Screening (Prompt Injection & Off-Topic)
    guard_result = inspect_input_guardrail(last_msg_content)
    if not guard_result.is_safe:
        logger.warning(f"Router Node intercepted by Guardrail: {guard_result.reason}")
        return {
            "intent": guard_result.category or "injection",
            "next_node": "llm_generator_node",
            "tool_calls": [],
            "final_response": guard_result.deflection_message
        }

    # 2. Prioritize pending action confirmation/denial
    pending_action = state.get("pending_action")
    confirm_words = ["yes", "confirm", "approve", "do it", "y", "proceed", "prcoeed", "sure", "ok", "submit", "yeah", "yep", "go ahead"]
    denial_words = ["no", "cancel", "deny", "n", "stop", "abort", "don't", "dont"]
    
    is_confirmation = any(w == last_msg_lower or last_msg_lower.startswith(w + " ") for w in confirm_words)
    is_denial = any(w == last_msg_lower or last_msg_lower.startswith(w + " ") for w in denial_words)
    
    if pending_action and (is_confirmation or is_denial):
        return {
            "intent": "tool_write",
            "pending_action": pending_action,
            "next_node": "execute_tools_node",
            "tool_calls": [pending_action] if is_confirmation else []
        }
    
    # 2.5 Fast-Path Rule-Based Routing (Sub-millisecond latency for common intents)
    greetings = {"hi", "hello", "hey", "hola", "namaste", "good morning", "good afternoon", "good evening", "how are you", "who are you", "help"}
    clean_msg = last_msg_lower.strip("!?. ,")
    if clean_msg in greetings or any(clean_msg.startswith(g + " ") for g in greetings if len(g) > 2):
        logger.info("Fast-Path Router: Instant match for 'chat' greeting")
        return {"intent": "chat", "next_node": "llm_generator_node", "tool_calls": []}

    leave_bal_triggers = ["balance leave", "leave balance", "remaining leave", "check leave", "how many leaves", "my leave", "leaves left", "my leaves"]
    if any(t in last_msg_lower for t in leave_bal_triggers) and not any(w in last_msg_lower for w in ["apply", "cancel", "request", "reject", "approve"]):
        logger.info("Fast-Path Router: Instant match for 'get_leave_balances'")
        params = {"action": "get_leave_balances", "target_module": "leave"}
        return {
            "intent": "tool_read",
            "pending_action": state.get("pending_action"),
            "next_node": "execute_tools_node",
            "tool_calls": [params]
        }

    punch_triggers = ["punch status", "punch out status", "punch in status", "today punch", "check punch", "my punch", "punched in", "punched out"]
    if any(t in last_msg_lower for t in punch_triggers) and not any(w in last_msg_lower for w in ["punch in now", "punch out now"]):
        logger.info("Fast-Path Router: Instant match for 'get_today_punch_status'")
        params = {"action": "get_today_punch_status", "target_module": "attendance"}
        return {
            "intent": "tool_read",
            "pending_action": state.get("pending_action"),
            "next_node": "execute_tools_node",
            "tool_calls": [params]
        }

    task_triggers = ["my tasks", "today tasks", "task status", "tasks for today", "show my tasks", "show tasks"]
    if any(t in last_msg_lower for t in task_triggers) and not any(w in last_msg_lower for w in ["log task", "start task", "finish task", "pause task"]):
        logger.info("Fast-Path Router: Instant match for 'get_my_tasks'")
        params = {"action": "get_my_tasks", "target_module": "tasks"}
        return {
            "intent": "tool_read",
            "pending_action": state.get("pending_action"),
            "next_node": "execute_tools_node",
            "tool_calls": [params]
        }

    wfh_status_triggers = ["wfh status", "check wfh", "my wfh", "wfh request", "wfh requests", "work from home status", "check my wfh"]
    if any(t in last_msg_lower for t in wfh_status_triggers) and not any(w in last_msg_lower for w in ["apply", "submit", "request wfh tomorrow", "cancel"]):
        logger.info("Fast-Path Router: Instant match for 'get_wfh_status'")
        params = {"action": "get_wfh_status", "target_module": "wfh"}
        return {
            "intent": "tool_read",
            "pending_action": state.get("pending_action"),
            "next_node": "execute_tools_node",
            "tool_calls": [params]
        }

    apply_howto_triggers = [
        "how to apply", "how do i apply", "how can i apply", "process to apply",
        "steps to apply", "procedure to apply", "how to take leave", "how to request leave",
        "apply leave procedure", "apply for leave procedure", "where to apply"
    ]
    if any(t in last_msg_lower for t in apply_howto_triggers):
        logger.info("Fast-Path Router: Instant match for 'how to apply' -> policy")
        return {
            "intent": "policy",
            "pending_action": state.get("pending_action"),
            "next_node": "retrieve_policy_node",
            "tool_calls": []
        }

    policy_triggers = [
        "policy", "late mark", "grace period", "probation", "notice period", 
        "dress code", "working hours", "late policy", "rules", "leave rules", 
        "leave policy", "attendance policy", "early go policy", "short leave policy",
        "roster policy"
    ]
    if any(t in last_msg_lower for t in policy_triggers) and not any(w in last_msg_lower for w in ["apply for", "cancel leave", "reject leave", "approve leave"]):
        logger.info("Fast-Path Router: Instant match for 'policy'")
        return {
            "intent": "policy",
            "pending_action": state.get("pending_action"),
            "next_node": "retrieve_policy_node",
            "tool_calls": []
        }

    # 3. Fallback to LLM classification with router prompt for complex / write actions
    provider = get_llm_provider()
    recent_msgs = messages[-5:] if len(messages) > 5 else messages
    formatted_msgs = [{"role": m["role"], "content": m["content"]} for m in recent_msgs]
    
    # Inject current date into router prompt
    today = datetime.date.today()
    cur_year = today.year
    date_context = f"\n\nCURRENT DATE CONTEXT:\n- Today's date: {today.strftime('%Y-%m-%d')} (Current Year: {cur_year})\n- When user specifies dates without a year (like '2 sep to 5 sep', 'next Monday', 'tomorrow'), resolve the year to {cur_year} (e.g. '{cur_year}-09-02'). NEVER use 2023 or any previous year."
    
    try:
        response = provider.generate(
            system_prompt=ROUTER_PROMPT + date_context,
            messages=formatted_msgs,
            response_format="json"
        )
        
        raw_json = response.get("content", "{}")
        parsed = json.loads(raw_json)
        intent = parsed.get("intent", "chat")
        
        # Check if LLM router classified intent as off_topic or injection
        if intent == "injection":
            return {
                "intent": "injection",
                "next_node": "llm_generator_node",
                "tool_calls": [],
                "final_response": DEFLECTION_PROMPT_INJECTION
            }
        elif intent == "off_topic":
            # Safety check: If user query contains AMS context, NEVER deflect as off_topic!
            if contains_ams_context(last_msg_lower):
                logger.warning(f"Router misclassified AMS query as off_topic: '{last_msg_lower}'. Rerouting to policy.")
                return {
                    "intent": "policy",
                    "next_node": "retrieve_policy_node",
                    "tool_calls": []
                }
            return {
                "intent": "off_topic",
                "next_node": "llm_generator_node",
                "tool_calls": [],
                "final_response": DEFLECTION_OFF_TOPIC
            }
        
        # Normalize extracted dates to current year if model defaulted to previous year
        params = parsed.get("parameters", {})
        if not isinstance(params, dict):
            params = {}
        # Keep explicit remote-work requests out of the leave/RO tool path.
        is_wfh_request = any(term in last_msg_lower for term in ["work from home", "apply wfh", "request wfh", "wfh request", "remote work"])
        is_action_request = any(term in last_msg_lower for term in ["apply", "request", "submit"])
        if intent == "tool_write" and is_wfh_request and is_action_request:
            intent = "tool_write"
            params["action"] = "apply_wfh"
            if not isinstance(params.get("details"), dict):
                params["details"] = {}
        if isinstance(params, dict):
            details = params.get("details", {})
            if isinstance(details, dict):
                if "from_date" in details:
                    details["from_date"] = normalize_date_year(details["from_date"])
                if "to_date" in details:
                    details["to_date"] = normalize_date_year(details["to_date"])
                    
        logger.info(f"Routed Intent: {intent} | Action: {params.get('action') if isinstance(params, dict) else None}")
        
        next_node = "llm_generator_node"
        if intent == "policy":
            next_node = "retrieve_policy_node"
        elif intent in ["tool_read", "tool_write"]:
            next_node = "execute_tools_node"
            
        return {
            "intent": intent,
            "pending_action": params if intent == "tool_write" else state.get("pending_action"),
            "next_node": next_node,
            "tool_calls": [params] if intent in ["tool_read", "tool_write"] else []
        }
    except Exception as e:
        logger.error(f"Router node failed: {e}")
        return {"intent": "chat", "next_node": "llm_generator_node", "tool_calls": []}

def retrieve_policy_node(state: AgentState) -> dict:
    """Query Qdrant vector database or fallback knowledge for business guidelines."""
    logger.info("Entering Retrieve Policy Node")
    last_message = state["messages"][-1]["content"]
    last_msg_lower = last_message.lower()
    
    results = []
    try:
        role = state["user_context"].get("role", "junior_intern")
        results = query_policy(last_message, user_role=role)
        logger.info(f"Retrieved {len(results)} chunks from Qdrant.")
    except Exception as e:
        logger.warning(f"RAG policy retrieval error (falling back to local policies): {e}")

    # If vector search returned 0 results (e.g. Qdrant running in-memory or low score), use authoritative fallback policies
    if not results:
        matched = []
        for p in FALLBACK_POLICIES:
            if any(k in last_msg_lower for k in p["keywords"]):
                matched.append({"topic": p["topic"], "content": p["content"], "version": "2026-08"})
        if matched:
            results = matched[:2]
        else:
            results = [{"topic": FALLBACK_POLICIES[0]["topic"], "content": FALLBACK_POLICIES[0]["content"], "version": "2026-08"}]
        logger.info(f"Using {len(results)} fallback policy documents.")

    return {
        "retrieved_policies": results,
        "next_node": "llm_generator_node"
    }

def execute_tools_node(state: AgentState) -> dict:
    """Runs database operations after verifying permissions."""
    logger.info("Entering Execute Tools Node")
    tool_calls = state.get("tool_calls", [])
    user = state["user_context"]
    tool_results = []
    
    # Process confirmation logic for writes
    last_msg = state["messages"][-1]["content"].lower().strip()
    confirm_words = ["yes", "confirm", "y", "do it", "proceed", "prcoeed", "sure", "ok", "submit", "yeah", "yep", "go ahead"]
    denial_words = ["no", "cancel", "n", "stop", "abort", "don't", "dont"]
    
    is_confirm = any(w == last_msg or last_msg.startswith(w + " ") for w in confirm_words)
    is_cancel = any(w == last_msg or last_msg.startswith(w + " ") for w in denial_words)
    
    if state.get("confirmation_required") and state.get("pending_action"):
        if is_confirm:
            # User confirmed the action, proceed to execute it
            tool_calls = [state["pending_action"]]
            logger.info(f"User confirmed action: {tool_calls[0].get('action')}")
        elif is_cancel:
            logger.info("User cancelled write action.")
            return {
                "confirmation_required": False,
                "pending_action": None,
                "tool_results": [{"success": False, "message": "Action cancelled by user."}],
                "next_node": "llm_generator_node"
            }
            
    for tc in tool_calls:
        if not isinstance(tc, dict):
            continue
        action = tc.get("action")
        details = tc.get("details", {})
        if not isinstance(details, dict):
            details = {}
        
        # 1. State-mutating writes require validation and confirmation step
        is_write = action in ["apply_leave", "request_leave", "apply_wfh", "cancel_leave", "punch_out", "approve_leave", "reject_leave"]
        
        # Validate and normalize mandatory dates and leave_type before asking for confirmation
        if action in ["apply_leave", "request_leave", "apply_wfh"]:
            from_date = normalize_date_year(details.get("from_date"))
            to_date = normalize_date_year(details.get("to_date"))
            details["from_date"] = from_date
            details["to_date"] = to_date

            if action == "apply_wfh":
                if not from_date or not to_date or not details.get("reason"):
                    return {
                        "confirmation_required": False,
                        "pending_action": None,
                        "tool_results": [{
                            "success": False,
                            "message": "To apply for work from home, please provide the start date, end date, and reason."
                        }],
                        "next_node": "llm_generator_node"
                    }
            else:
                raw_lt = str(details.get("leave_type") or "").strip().upper()
                if not raw_lt:
                    return {
                        "confirmation_required": False,
                        "pending_action": None,
                        "tool_results": [{"success": False, "message": "Please specify the leave type (CL, SL, PL, TD, or RO) before I submit a leave application."}],
                        "next_node": "llm_generator_node"
                    }
            
            # Normalize natural language leave type strings to system codes
            raw_lt = str(details.get("leave_type") or "").strip().upper()
            if any(k in raw_lt for k in ["SL", "SICK"]):
                leave_type = "SL"
            elif any(k in raw_lt for k in ["PL", "PRIVILEGE", "EARNED"]):
                leave_type = "PL"
            elif any(k in raw_lt for k in ["TD", "TOUR"]):
                leave_type = "TD"
            elif any(k in raw_lt for k in ["RO", "ROSTER"]):
                leave_type = "RO"
            elif any(k in raw_lt for k in ["CL", "CASUAL"]):
                leave_type = "CL"
            else:
                leave_type = ""

            if action != "apply_wfh" and not leave_type:
                return {
                    "confirmation_required": False,
                    "pending_action": None,
                    "tool_results": [{"success": False, "message": "I couldn't identify a valid leave type. Please choose CL, SL, PL, TD, or RO."}],
                    "next_node": "llm_generator_node"
                }
                
            details["leave_type"] = leave_type
            
            if not from_date or not to_date or str(from_date).lower() in ["none", "null", "start date", "from", "start"] or str(to_date).lower() in ["none", "null", "end date", "to", "end"]:
                return {
                    "confirmation_required": False,
                    "pending_action": None,
                    "tool_results": [{
                        "success": False,
                        "message": f"To apply for leave, please provide the start date, end date, and reason (for example: 'apply for CL from {datetime.date.today().year}-09-05 to {datetime.date.today().year}-09-06 due to fever')."
                    }],
                    "next_node": "llm_generator_node"
                }
                
        if is_write and not is_confirm:
            logger.info(f"Write action '{action}' requires validation. Halting for confirmation.")
            return {
                "confirmation_required": True,
                "pending_action": tc,
                "next_node": "llm_generator_node"
            }
            
        # 2. Check Permissions & Execute Tool
        res = {"success": False, "message": "Access Denied: Insufficient permissions."}
        try:
            if action in ["get_leave_balances", "get_leave_balance", "check_leave_balance", "leave_balance", "get_leaves"]:
                res = get_leave_balances(user["user_id"])
            elif action in ["apply_leave", "request_leave"]:
                res = apply_leave(
                    user_id=user["user_id"],
                    leave_type=details.get("leave_type", "CL"),
                    from_date=details.get("from_date"),
                    to_date=details.get("to_date"),
                    reason=details.get("reason", "Applied via AI Chatbot")
                )
            elif action == "apply_wfh":
                res = apply_wfh(
                    user_id=user["user_id"],
                    from_date=details.get("from_date"),
                    to_date=details.get("to_date"),
                    reason=details.get("reason")
                )
            elif action in ["cancel_leave", "withdraw_leave"]:
                res = cancel_leave(user["user_id"], details.get("leave_id"))
            elif action in ["get_pending_leaves", "list_pending_leaves"]:
                if has_permission(user["role"], user["designation"], ["leave.approve"]):
                    res = get_pending_leaves()
            elif action in ["approve_leave"]:
                if has_permission(user["role"], user["designation"], ["leave.approve"]):
                    res = approve_leave(details.get("leave_id"), approver_id=user["user_id"])
            elif action in ["reject_leave"]:
                if has_permission(user["role"], user["designation"], ["leave.approve"]):
                    res = reject_leave(details.get("leave_id"), approver_id=user["user_id"])
            elif action in ["get_attendance"]:
                res = get_attendance_range(user["user_id"], details.get("from_date"), details.get("to_date"))
            elif action in ["punch_in"]:
                res = punch_in(user["user_id"])
            elif action in ["punch_out"]:
                res = punch_out(user["user_id"])
            elif action in ["check_punch_out", "check_punch_out_status"]:
                res = check_punch_out_status(user["user_id"])
            elif action in ["get_today_punch_status", "get_punch_status", "punch_status"]:
                res = get_today_punch_status(user["user_id"])
            elif action in ["log_task"]:
                res = log_task(user["user_id"], details.get("project", "General"), details.get("description", "Task log"), details.get("duration", 1.0))
            elif action in ["pause_task"]:
                res = pause_task(user["user_id"], details.get("task_id"))
            elif action in ["resume_task"]:
                res = resume_task(user["user_id"], details.get("task_id"))
            elif action in ["finish_task"]:
                res = finish_task(user["user_id"], details.get("task_id"))
            elif action in ["get_my_tasks", "get_tasks"]:
                res = get_my_tasks(user["user_id"])
            elif action in ["get_wfh_status", "check_wfh_status", "my_wfh"]:
                res = get_wfh_status(user["user_id"])
            else:
                res = {"success": False, "message": f"Action '{action}' not recognized."}
                
        except Exception as e:
            logger.error(f"Error executing tool '{action}': {e}")
            res = {"success": False, "message": f"Tool execution error: {e}"}
            
        tool_results.append(res)
        
    return {
        "tool_results": tool_results,
        "confirmation_required": False,
        "pending_action": None,
        "next_node": "llm_generator_node"
    }

def llm_generator_node(state: AgentState) -> dict:
    """Synthesizes final natural language response based on prompt context and enforces output guardrails."""
    logger.info("Entering LLM Generator Node")
    
    # 0. Check if a deflection message was already set by the input guardrail or router
    if state.get("final_response"):
        resp = state.get("final_response")
        return {
            "messages": [{"role": "assistant", "content": resp}],
            "final_response": resp
        }
        
    messages = state["messages"]
    policies = state.get("retrieved_policies", [])
    results = state.get("tool_results", [])
    
    # 1. Check if we need confirmation from the user
    if state.get("confirmation_required") and state.get("pending_action"):
        pending = state.get("pending_action") or {}
        if not isinstance(pending, dict):
            pending = {}
        act = pending.get("action")
        det = pending.get("details")
        if not isinstance(det, dict):
            det = {}
        
        LEAVE_DISPLAY_MAP = {
            "CL": "Casual Leave (CL)",
            "SL": "Sick Leave (SL)",
            "PL": "Privilege Leave (PL)",
            "TD": "Tour Day (TD)",
            "RO": "Rostering Off (RO)"
        }
        
        if act == "apply_leave":
            code = det.get('leave_type', 'CL')
            l_type_display = LEAVE_DISPLAY_MAP.get(code, code)
            f_date = det.get('from_date', 'start date')
            t_date = det.get('to_date', 'end date')
            msg = f"I detected you want to apply for **{l_type_display}** from **{f_date}** to **{t_date}**. Shall I proceed and submit this application? (Reply **Yes** or **No**)"
        elif act == "apply_wfh":
            msg = f"I detected you want to apply for **Work From Home** from **{det.get('from_date', 'start date')}** to **{det.get('to_date', 'end date')}**. Shall I submit this WFH request? (Reply **Yes** or **No**)"
        elif act == "cancel_leave":
            msg = f"You are asking to cancel leave request ID {det.get('leave_id', '')}. Are you sure you want to cancel this?"
        elif act == "punch_out":
            msg = "You are trying to punch out for the day. Please confirm if you wish to register your exit punch."
        elif act == "approve_leave":
            msg = f"Do you want to approve leave request ID {det.get('leave_id', '')}?"
        else:
            msg = f"Please confirm if you want to execute {act}."
            
        return {
            "messages": [{"role": "assistant", "content": msg}],
            "final_response": msg
        }
        
    # 2. Construct prompt context
    u_ctx = state.get("user_context") or {}
    today_now = datetime.date.today()
    prompt = SYSTEM_PROMPT + "\n\n"
    prompt += f"Today's Date: {today_now.strftime('%Y-%m-%d')} (Current Year: {today_now.year})\n"
    prompt += f"Active User: {u_ctx.get('fullName', 'User')} (ID: {u_ctx.get('user_id', '')}, Role: {u_ctx.get('role', '')}, Designation: {u_ctx.get('designation', '')})\n\n"
    
    if policies:
        prompt += "### Authoritative Policy Documents:\n"
        for doc in policies:
            prompt += f"- Topic: {doc.get('topic')}\n  Content: {doc.get('content')}\n"
        prompt += "\n"
        
    if results:
        prompt += "### Query/Action Tool Results:\n"
        for r in results:
            content_desc = r.get('data') if r.get('data') is not None else r.get('message')
            if content_desc is None:
                filtered = {k: v for k, v in r.items() if k != 'success'}
                content_desc = filtered
            msg_note = f" (Note: {r.get('message')})" if r.get('message') else ""
            prompt += f"- Status: {'Success' if r.get('success') else 'Failed'}{msg_note} | Data: {content_desc}\n"
        prompt += (
            "\nInstructions: Inform the user of the above result clearly, politely, directly, and definitively. "
            "Never ask the user if they want to retrieve the tasks or confirm retrieval; simply present the results right away. "
            "If the results indicate 0 tasks or empty tasks, explicitly state that the user currently has no active allocated tasks or diary entries logged for today. "
            "If there are tasks, display them in a clean markdown table or structured list with Project, Task/Title, Deadline/Time, and Status. "
            "Never output raw JSON or emojis.\n\n"
        )

    # Call LLM to generate final response
    provider = get_llm_provider()
    recent_msgs = messages[-8:] if len(messages) > 8 else messages
    formatted_msgs = [{"role": m["role"], "content": m["content"]} for m in recent_msgs]
    
    try:
        response = provider.generate(
            system_prompt=prompt,
            messages=formatted_msgs
        )
        raw_content = response.get("content")
        content = raw_content.strip() if (raw_content and isinstance(raw_content, str)) else ""
        
        # 3. Apply Output Guardrail Check
        out_guard = inspect_output_guardrail(content)
        if not out_guard.is_safe:
            logger.warning(f"Output Guardrail intercepted generation: {out_guard.reason}")
            content = out_guard.deflection_message or DEFLECTION_OFF_TOPIC
            
        if not content:
            if results:
                last_res = results[-1]
                msg_txt = last_res.get("message") or ("Action completed successfully." if last_res.get("success") else "Could not complete the action.")
                content = str(msg_txt)
            else:
                content = "I have processed your request."
                
        return {
            "messages": [{"role": "assistant", "content": content}],
            "final_response": content
        }
    except Exception as e:
        logger.error(f"Generation failed: {e}")
        if results:
            last_res = results[-1]
            content = last_res.get("message") or ("Action completed successfully." if last_res.get("success") else "Could not complete the action.")
        else:
            content = "I have processed your request."
        return {
            "messages": [{"role": "assistant", "content": content}],
            "final_response": content
        }

# Build LangGraph state machine workflow
workflow = StateGraph(AgentState)

# Register nodes
workflow.add_node("router_node", router_node)
workflow.add_node("retrieve_policy_node", retrieve_policy_node)
workflow.add_node("execute_tools_node", execute_tools_node)
workflow.add_node("llm_generator_node", llm_generator_node)

# Set entry point
workflow.set_entry_point("router_node")

# Define state transitions
def determine_next_node(state: AgentState) -> str:
    return state.get("next_node", "llm_generator_node")

workflow.add_conditional_edges(
    "router_node",
    determine_next_node,
    {
        "retrieve_policy_node": "retrieve_policy_node",
        "execute_tools_node": "execute_tools_node",
        "llm_generator_node": "llm_generator_node"
    }
)

workflow.add_edge("retrieve_policy_node", "llm_generator_node")
workflow.add_edge("execute_tools_node", "llm_generator_node")
workflow.add_edge("llm_generator_node", END)

# Compile graph
agent_graph = workflow.compile()
