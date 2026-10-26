import os
import sys
sys.path.append(os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))
import numpy as np
import cv2
from ai_service.db_manager import DatabaseManager
from ai_service.face_engine import FaceEngine

def register():
    db = DatabaseManager()
    conn = db.get_connection()
    cur = conn.cursor(dictionary=True)
    
    # Check if user already exists
    cur.execute("SELECT userId FROM signup WHERE username='tushar.singh' OR fullName='Tushar Singh'")
    existing = cur.fetchone()
    
    user_id = existing['userId'] if existing else '66f0a1b2c3d4e'
    photo_file = 'tushar_singh.jpg'
    
    if not existing:
        print("[*] Inserting Tushar Singh into signup table...")
        # Pre-hashed bcrypt for 'ams@123'
        hashed_pwd = '$2y$10$e0MYzXyjpJS7Pd0RVvHwHeFjKN.Id70FIJ1fioCWPWpuDAv8P163O'
        cur.execute("""
            INSERT INTO signup (
                userId, emp_id, fullName, email, mobileNo, designation, mode, 
                username, password, profilePhoto, createdDate, expectedPunchIn, 
                expectedPunchOut, type, status, office, userRole, regstatus, face_detection_enabled
            ) VALUES (
                %s, %s, %s, %s, %s, %s, %s,
                %s, %s, %s, NOW(), '10:00:00',
                '18:30:00', 'Online', 'Active', 'Latur', 'Employee', 'Approved', 1
            )
        """, (
            user_id, 'EMP099', 'Tushar Singh', 'tusharsingh@settribe.com', 9876543210,
            'developer', 'Online', 'tushar.singh', hashed_pwd, photo_file
        ))
        conn.commit()
        print("[OK] User Tushar Singh inserted successfully with ID:", user_id)
    else:
        print("[*] User already exists with ID:", user_id, "- Updating profile photo...")
        cur.execute("UPDATE signup SET profilePhoto=%s, regstatus='Approved', status='Active' WHERE userId=%s", (photo_file, user_id))
        conn.commit()
        print("[OK] User updated.")

    cur.close()
    conn.close()

    # Enroll in FaceEngine
    photo_path = os.path.join(os.path.dirname(__file__), "..", "profile-photos", photo_file)
    print("[*] Enrolling Tushar Singh in FaceEngine with photo:", photo_path)
    
    engine = FaceEngine()
    record = engine.enroll_person(
        user_id=user_id,
        name="Tushar Singh",
        image_path=photo_path,
        metadata={
            "role": "developer",
            "expected_in": "10:00:00",
            "username": "tushar.singh"
        }
    )
    print(f"[OK] Successfully enrolled Tushar Singh! Total registered faces in face_db.json: {len(engine.db)}")

if __name__ == '__main__':
    register()
