import os
import sys
import logging
from qdrant_client.http import models as rest_models

# Ensure app path is in system path for direct running
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from app.rag.qdrant import qdrant_client, COLLECTION_NAME, ensure_collection
from app.rag.embeddings import embedding_model

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("ams-ai.scripts.ingest")

POLICIES = [
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
            "Formula: floor((lateCount - 2) / 2) * 0.5 days deduction.\n"
            "This policy applies strictly starting from 2026-08-01 to senior interns, resource Allocators, and HR designation."
        ),
        "roles": ["junior_intern", "senior_intern", "hr", "admin", "resourcehead", "md"],
        "version": "2026-08",
        "category": "policy"
    },
    {
        "topic": "Early Go Policy",
        "content": (
            "Leaving before standard office exit hour (6:30 PM) without approval constitutes Early Go.\n"
            "Early Departure Rules:\n"
            "- Departure up to 15 minutes early: Allowed occasionally (0 mark).\n"
            "- Departure between 15 to 120 minutes early: Receives 1 Early Go Mark.\n"
            "- Departure more than 120 minutes (2 hours) early: Evaluated as Half Day.\n"
            "Monthly Early Go Penalty:\n"
            "- 3 Early Go Marks in a month: Triggers a warning notification.\n"
            "- Every 2 additional unapproved marks after the warning (5th, 7th, 9th...) triggers a Half Day Leave deduction.\n"
            "Approved early departures (hr_granted = 1) do NOT count toward the monthly early departure penalty mark counter."
        ),
        "roles": ["junior_intern", "senior_intern", "hr", "admin", "resourcehead", "md"],
        "version": "2026-08",
        "category": "policy"
    },
    {
        "topic": "Short Leave Policy",
        "content": (
            "Employees can take short leaves for bank, hospital, or government office visits.\n"
            "Short Leave rules:\n"
            "- Free quota: 2 Short Leaves per month, maximum of 2 hours duration each.\n"
            "- Unused short leaves do NOT carry forward to the next month.\n"
            "- Additional short leaves beyond the free quota of 2 will be adjusted against the user's main leave balance, costing 0.5 days each.\n"
            "- Duration must be between 1 minute and 2 hours."
        ),
        "roles": ["junior_intern", "senior_intern", "hr", "admin", "resourcehead", "md"],
        "version": "2026-08",
        "category": "policy"
    },
    {
        "topic": "Rostering Off (RO) Policy",
        "content": (
            "Rostering Off (RO) is a Saturday-only leave auto-approved by standard rules.\n"
            "RO rules:\n"
            "- Maximum RO limit: 2 days per calendar month for offline interns.\n"
            "- RO leaves must strictly fall on Saturdays (no other days allowed).\n"
            "- Balance check evaluates pending RO leaves to ensure they do not exceed monthly quota."
        ),
        "roles": ["junior_intern", "senior_intern", "hr", "admin", "resourcehead", "md"],
        "version": "2026-08",
        "category": "policy"
    },
    {
        "topic": "General Leave and WFH Policies",
        "content": (
            "Available leaves: CL (Casual Leave), SL (Sick Leave), PL (Privilege Leave), TD (Tour Day), RO (Rostering Off).\n"
            "Guidelines:\n"
            "- Leave applications must be submitted in advance. Passed dates are blocked.\n"
            "- Sundays cannot be selected as start or end date of leave.\n"
            "- Sick Leave (SL) requires uploading medical documentation to R2 if it exceeds 1 day.\n"
            "- Overlapping leaves, WFH requests, or Tour applications for the same dates are strictly blocked."
        ),
        "roles": ["junior_intern", "senior_intern", "hr", "admin", "resourcehead", "md"],
        "version": "2026-08",
        "category": "policy"
    }
]

def ingest_policies():
    ensure_collection()
    
    logger.info("Starting ingestion of policies into Qdrant...")
    points = []
    
    for idx, p in enumerate(POLICIES):
        text = f"{p['topic']}\n{p['content']}"
        vector = embedding_model.get_embedding(text)
        
        points.append(
            rest_models.PointStruct(
                id=idx,
                vector=vector,
                payload={
                    "topic": p["topic"],
                    "content": p["content"],
                    "roles": p["roles"],
                    "version": p["version"],
                    "category": p["category"]
                }
            )
        )
        
    qdrant_client.upsert(
        collection_name=COLLECTION_NAME,
        points=points
    )
    logger.info("Ingestion completed successfully!")

if __name__ == "__main__":
    ingest_policies()
