import os
import time
import cv2
import numpy as np
import winsound

from db_manager import DatabaseManager
from face_engine import FaceEngine

def main():
    print("=" * 60)
    print("   SETTribe Automated Face Recognition Attendance Kiosk")
    print("=" * 60)

    db = DatabaseManager()
    engine = FaceEngine()

    if not engine.known_faces:
        print("[*] Fetching employees and building face cache...")
        employees = db.get_active_employees()
        engine.build_cache(employees)

    print("[*] Opening camera...")
    cap = cv2.VideoCapture(0)
    if not cap.isOpened():
        print("[!] Error: Could not open webcam.")
        return

    cap.set(cv2.CAP_PROP_FRAME_WIDTH, 1280)
    cap.set(cv2.CAP_PROP_FRAME_HEIGHT, 720)

    cooldown_seconds = 15
    user_cooldowns = {}
    
    # Visual notification banner state: (message, color, expiry_time)
    notification = None

    last_recognition_time = 0
    recognition_interval = 0.5  # Run deep recognition at most every 500ms to keep video smooth 30 FPS

    current_match = None

    print("[OK] Camera started. Press 'q' or ESC to exit.")

    while True:
        ret, frame = cap.read()
        if not ret:
            break

        # Mirror display for natural feeling
        display_frame = cv2.flip(frame, 1)
        h, w, _ = display_frame.shape

        # Run fast face detection
        faces = engine.detect_faces_fast(display_frame)
        now = time.time()

        if len(faces) > 0:
            best_face = max(faces, key=lambda b: b[2] * b[3])
            x, y, fw, fh = best_face

            # Periodically run full face identification
            if (now - last_recognition_time) >= recognition_interval:
                last_recognition_time = now
                current_match = engine.match_face(display_frame)

                if current_match.get("recognized"):
                    uid = current_match["user_id"]
                    uname = current_match["name"]
                    sim = current_match["similarity"]
                    role = current_match.get("role", "")
                    exp_in = current_match.get("expected_in", "09:30:00")

                    # Check cooldown
                    last_punch = user_cooldowns.get(uid, 0)
                    if (now - last_punch) >= cooldown_seconds:
                        # Mark attendance in database!
                        res = db.mark_attendance(uid, uname, role, exp_in)
                        user_cooldowns[uid] = now
                        
                        # Audio chime
                        try:
                            winsound.Beep(1200, 200)
                            winsound.Beep(1600, 200)
                        except Exception:
                            pass

                        action = res.get("action")
                        if action == "punch_in":
                            msg = f"PUNCH IN DONE: {uname} at {res.get('time')}"
                            notification = (msg, (0, 200, 0), now + 4.0)
                        elif action == "punch_out":
                            msg = f"PUNCH OUT DONE: {uname} (Hours: {res.get('man_hours')})"
                            notification = (msg, (220, 120, 0), now + 4.0)
                        else:
                            msg = f"{uname}: Attendance already done for today!"
                            notification = (msg, (0, 165, 255), now + 4.0)

            # Draw bounding box
            if current_match and current_match.get("recognized"):
                box_color = (0, 230, 0) # Green
                label = f"{current_match['name']} ({current_match['similarity']}%)"
            elif current_match and current_match.get("detected"):
                box_color = (0, 165, 255) # Orange (Unrecognized)
                label = f"Scanning... ({current_match.get('similarity', 0)}%)"
            else:
                box_color = (255, 255, 255)
                label = "Face Detected"

            # Draw rectangle and name badge
            cv2.rectangle(display_frame, (x, y), (x + fw, y + fh), box_color, 2)
            cv2.rectangle(display_frame, (x, y - 35), (x + fw, y), box_color, cv2.FILLED)
            cv2.putText(display_frame, label, (x + 6, y - 10), cv2.FONT_HERSHEY_SIMPLEX, 0.65, (0, 0, 0), 2)

        # Header overlay
        cv2.rectangle(display_frame, (0, 0), (w, 55), (30, 30, 30), cv2.FILLED)
        cv2.putText(display_frame, "SETTribe AMS - Live Face Attendance Kiosk", (20, 36), 
                    cv2.FONT_HERSHEY_SIMPLEX, 0.8, (255, 255, 255), 2)

        # Status banner overlay
        if notification:
            msg, color, exp = notification
            if now < exp:
                cv2.rectangle(display_frame, (0, h - 70), (w, h), color, cv2.FILLED)
                cv2.putText(display_frame, msg, (30, h - 25), cv2.FONT_HERSHEY_SIMPLEX, 0.9, (255, 255, 255), 2)
            else:
                notification = None

        cv2.imshow("SETTribe AMS Live Face Attendance", display_frame)

        key = cv2.waitKey(1) & 0xFF
        if key == ord('q') or key == 27:
            break

    cap.release()
    cv2.destroyAllWindows()

if __name__ == '__main__':
    main()
