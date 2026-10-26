from typing import List, Dict, Set

# Permission constants
P_ATTENDANCE_READ_SELF = "attendance.read.self"
P_ATTENDANCE_READ_TEAM = "attendance.read.team"
P_ATTENDANCE_PUNCH = "attendance.punch"

P_LEAVE_READ_SELF = "leave.read.self"
P_LEAVE_CREATE = "leave.create"
P_LEAVE_APPROVE = "leave.approve"
P_LEAVE_REJECT = "leave.reject"
P_LEAVE_CANCEL = "leave.cancel"

P_TASK_READ_SELF = "task.read.self"
P_TASK_CREATE = "task.create"
P_TASK_PAUSE = "task.pause"
P_TASK_RESUME = "task.resume"
P_TASK_END = "task.end"
P_TASK_READ_TEAM = "task.read.team"

P_EARLY_GO_READ = "early_go.read"
P_EARLY_GO_APPROVE = "early_go.approve"

P_RESOURCE_READ = "resource.read"
P_RESOURCE_ALLOCATE = "resource.allocate"

P_MANAGEMENT_ANALYTICS = "management.analytics"

# Role & Designation permission matrices
# Permission sets by responsibility
EMPLOYEE_PERMISSIONS: Set[str] = {
    P_ATTENDANCE_READ_SELF, P_ATTENDANCE_PUNCH,
    P_LEAVE_READ_SELF, P_LEAVE_CREATE, P_LEAVE_CANCEL,
    P_TASK_READ_SELF, P_TASK_CREATE, P_TASK_PAUSE, P_TASK_RESUME, P_TASK_END,
    P_EARLY_GO_READ
}

TEAM_LEAD_PERMISSIONS: Set[str] = EMPLOYEE_PERMISSIONS | {
    P_ATTENDANCE_READ_TEAM,
    P_TASK_READ_TEAM,
    P_RESOURCE_READ,
    P_RESOURCE_ALLOCATE
}

MANAGER_PERMISSIONS: Set[str] = TEAM_LEAD_PERMISSIONS | {
    P_LEAVE_APPROVE,
    P_LEAVE_REJECT,
    P_EARLY_GO_APPROVE,
    P_MANAGEMENT_ANALYTICS
}

ADMIN_PERMISSIONS: Set[str] = {
    P_ATTENDANCE_READ_SELF, P_ATTENDANCE_READ_TEAM, P_ATTENDANCE_PUNCH,
    P_LEAVE_READ_SELF, P_LEAVE_CREATE, P_LEAVE_APPROVE, P_LEAVE_REJECT, P_LEAVE_CANCEL,
    P_TASK_READ_SELF, P_TASK_CREATE, P_TASK_PAUSE, P_TASK_RESUME, P_TASK_END, P_TASK_READ_TEAM,
    P_EARLY_GO_READ, P_EARLY_GO_APPROVE, P_RESOURCE_READ, P_RESOURCE_ALLOCATE, P_MANAGEMENT_ANALYTICS
}

MD_PERMISSIONS: Set[str] = {
    P_ATTENDANCE_READ_SELF, P_ATTENDANCE_READ_TEAM,
    P_LEAVE_READ_SELF, P_LEAVE_CANCEL, P_LEAVE_APPROVE, P_LEAVE_REJECT,
    P_TASK_READ_SELF, P_TASK_READ_TEAM,
    P_RESOURCE_READ, P_MANAGEMENT_ANALYTICS
}

def _normalize_key(key: str) -> str:
    return (key or "").lower().strip().replace(" ", "").replace("_", "").replace("-", "")

# Normalized matrix for all roles & designations across SETTribe AMS
ROLE_PERMISSIONS: Dict[str, Set[str]] = {
    "admin": ADMIN_PERMISSIONS,
    "hr": ADMIN_PERMISSIONS,
    "md": MD_PERMISSIONS,
    "manager": MANAGER_PERMISSIONS,
    "departmenthead": MANAGER_PERMISSIONS,
    "resourcehead": MANAGER_PERMISSIONS,
    "resourceallocator": TEAM_LEAD_PERMISSIONS,
    "teamlead": TEAM_LEAD_PERMISSIONS,
    "developer": EMPLOYEE_PERMISSIONS,
    "seniorintern": EMPLOYEE_PERMISSIONS,
    "juniorintern": EMPLOYEE_PERMISSIONS,
    "receptionist": EMPLOYEE_PERMISSIONS,
    "staff": EMPLOYEE_PERMISSIONS
}

def has_permission(user_role: str, user_designation: str, required_permissions: List[str]) -> bool:
    """
    Check if a user has all of the required permissions based on role and post.
    """
    norm_role = _normalize_key(user_role)
    norm_desig = _normalize_key(user_designation)

    # Accumulate user's permissions
    user_perms: Set[str] = set()

    # Match by role
    if norm_role in ROLE_PERMISSIONS:
        user_perms.update(ROLE_PERMISSIONS[norm_role])
    
    # Match by designation / post
    if norm_desig in ROLE_PERMISSIONS:
        user_perms.update(ROLE_PERMISSIONS[norm_desig])

    # Default fallback: Any logged-in member gets standard employee permissions
    if not user_perms:
        user_perms.update(EMPLOYEE_PERMISSIONS)

    # Check if all required permissions are satisfied
    return all(perm in user_perms for perm in required_permissions)
