import os
import sys
sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))
from ai_service.db_manager import DatabaseManager
from ai_service.face_engine import FaceEngine

print("=" * 60)
print("  SETTribe AMS - FaceVision AI Database Builder")
print("=" * 60)

db = DatabaseManager()
employees = db.get_active_employees()
print(f"[*] Fetched {len(employees)} active employees from MySQL (non-default photos).")

engine = FaceEngine()
engine.build_cache(employees)

print(f"[OK] Total genuine enrolled faces in face_db.json: {len(engine.db)}")
for r in engine.db:
    print(f"  [+] {r['name']} (User ID: {r['user_id']}, File: {r['image_file']})")
