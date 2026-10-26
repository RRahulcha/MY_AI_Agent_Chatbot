import json
import logging
import time
from fastapi import Request

class JsonLoggingFormatter(logging.Formatter):
    """Formats Python log records as structured JSON strings."""
    def format(self, record):
        log_record = {
            "timestamp": self.formatTime(record, "%Y-%m-%dT%H:%M:%S%z"),
            "level": record.levelname,
            "logger": record.name,
            "message": record.getMessage(),
            "filename": record.filename,
            "lineno": record.lineno
        }
        
        # Redaction pattern for sensitive payload data
        for sensitive_key in ["password", "token", "Pass", "git_access_link"]:
            if sensitive_key in log_record["message"]:
                log_record["message"] = f"[REDACTED] {sensitive_key} payload omitted."

        if record.exc_info:
            log_record["exc_info"] = self.formatException(record.exc_info)
            
        return json.dumps(log_record)

def setup_json_logging():
    """Applies the JSON formatter to the root logger handlers."""
    root_logger = logging.getLogger()
    root_logger.setLevel(logging.INFO)
    
    # Avoid duplicate handlers
    if not root_logger.handlers:
        handler = logging.StreamHandler()
        handler.setFormatter(JsonLoggingFormatter())
        root_logger.addHandler(handler)
    else:
        for h in root_logger.handlers:
            h.setFormatter(JsonLoggingFormatter())
