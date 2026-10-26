import os
import base64
import time
import cv2
import numpy as np
from flask import Flask, request, jsonify
from flask_cors import CORS

from db_manager import DatabaseManager
from face_engine import FaceEngine
from deepface import DeepFace

app = Flask(__name__)
CORS(app)

db = DatabaseManager()
engine = FaceEngine()

# In-memory cooldown dictionary to prevent multi-triggering: {user_id: last_punch_timestamp}
COOLDOWN_SECONDS = 15
user_cooldowns = {}

def initialize_system():
    if not engine.db:
        print("[*] face_db.json empty or not found. Fetching employees from database...")
        try:
            employees = db.get_active_employees()
            engine.build_cache(employees)
        except Exception as e:
            print(f"[!] Could not fetch employees on startup: {e}")

    print("[*] Warming up Face Engine model in memory...")
    try:
        dummy = np.zeros((160, 160, 3), dtype=np.uint8)
        DeepFace.represent(img_path=dummy, model_name=engine.model_name, detector_backend="skip", enforce_detection=False, align=False)
        print("[OK] Face Engine model warmed up and ready for instant inference!")
    except Exception as e:
        print(f"[!] Warmup note: {e}")

initialize_system()

@app.route('/', methods=['GET'])
@app.route('/health', methods=['GET'])
@app.route('/api/health', methods=['GET'])
@app.route('/api/status', methods=['GET'])
def get_status():
    return jsonify({
        "status": "online",
        "service": "SETTribe Face Attendance AI (FaceVision Engine)",
        "cached_employees": len(engine.db),
        "db_file": engine.db_file,
        "distance_threshold": engine.threshold,
        "similarity_threshold": engine.similarity_threshold,
        "cooldown_seconds": COOLDOWN_SECONDS
    })

@app.route('/api/reload_cache', methods=['POST'])
def reload_cache():
    try:
        employees = db.get_active_employees()
        engine.build_cache(employees)
        return jsonify({
            "success": True,
            "message": f"Successfully enrolled {len(engine.db)} employee face profiles in face_db.json."
        })
    except Exception as e:
        return jsonify({"success": False, "error": str(e)}), 500

@app.route('/api/enroll_face', methods=['POST'])
def enroll_face():
    """
    Enroll or update an individual employee's face profile immediately when
    they upload a new profile photo on the website.
    """
    try:
        data = request.get_json(silent=True) or request.form or {}
        user_id = str(data.get('user_id', '')).strip()
        name = str(data.get('name', '')).strip()
        photo_filename = str(data.get('photo_filename', '')).strip()

        if not user_id:
            return jsonify({"success": False, "face_detected": False, "message": "user_id is required"}), 400

        if not photo_filename:
            return jsonify({"success": False, "face_detected": False, "message": "photo_filename is required"}), 400

        photo_path = os.path.join(engine.photo_dir, os.path.basename(photo_filename))
        if not os.path.exists(photo_path):
            return jsonify({"success": False, "face_detected": False, "message": f"Photo file not found: {photo_filename}"}), 404

        try:
            record = engine.enroll_person(
                user_id=user_id,
                name=name or user_id,
                image_path=photo_path,
                metadata={
                    "role": data.get("role", ""),
                    "expected_in": data.get("expected_in", "09:30:00")
                }
            )
            return jsonify({
                "success": True,
                "face_detected": True,
                "message": f"Face verified and enrolled successfully for {record['name']}!",
                "user_id": user_id,
                "total_registered": len(engine.db)
            })
        except ValueError as ve:
            return jsonify({
                "success": False,
                "face_detected": False,
                "message": str(ve)
            }), 200  # Return 200 with face_detected: False so PHP can show clear notification
    except Exception as e:
        print(f"[!] Enroll face error: {e}")
        return jsonify({"success": False, "message": str(e)}), 500

def decode_base64_image(image_data):
    if not image_data:
        return None
    if 'base64,' in image_data:
        image_data = image_data.split('base64,')[1]
    image_bytes = base64.b64decode(image_data)
    np_arr = np.frombuffer(image_bytes, np.uint8)
    return cv2.imdecode(np_arr, cv2.IMREAD_COLOR)

@app.route('/api/get_employee_info', methods=['GET', 'POST'])
def get_employee_info():
    """Retrieve employee details for verification UI by userId or username"""
    try:
        user_id = request.args.get('user_id') or (request.get_json(silent=True) or {}).get('user_id') or request.form.get('user_id')
        if not user_id:
            return jsonify({"success": False, "message": "user_id is required"}), 400

        emp = db.get_employee_by_id(str(user_id).strip())
        if not emp:
            return jsonify({"success": False, "found": False, "message": f"Employee {user_id} not found in database"}), 404

        enrolled = any(str(r.get("user_id")) == str(emp["userId"]) for r in engine.db)

        return jsonify({
            "success": True,
            "found": True,
            "user_id": emp["userId"],
            "username": emp["username"],
            "full_name": emp["fullName"],
            "designation": emp.get("designation") or "",
            "role": emp.get("userRole") or "",
            "profile_photo": emp.get("profilePhoto") or "",
            "already_enrolled": enrolled
        })
    except Exception as e:
        return jsonify({"success": False, "error": str(e)}), 500

@app.route('/api/verify_and_register_face', methods=['POST'])
def verify_and_register_face():
    """
    3D Motion & Biometric Face Verification Endpoint (Google Auth / FaceID style):
    Accepts frontal, left, right, and liveness frames with employee details.
    """
    try:
        data = request.get_json(silent=True) or request.form or {}
        user_id = str(data.get('user_id', '')).strip()

        if not user_id:
            return jsonify({"success": False, "message": "user_id is required"}), 400

        emp = db.get_employee_by_id(user_id)
        if not emp:
            return jsonify({"success": False, "message": f"Employee ID '{user_id}' does not exist in system database."}), 404

        user_name = emp.get("fullName") or emp.get("username") or user_id
        user_role = emp.get("userRole") or ""
        expected_in = str(emp.get("expectedPunchIn") or "09:30:00")
        designation = emp.get("designation") or ""

        frontal_data = data.get('frontal_image') or data.get('image')
        left_data = data.get('left_image')
        right_data = data.get('right_image')
        liveness_data = data.get('liveness_image')

        if not frontal_data:
            return jsonify({"success": False, "message": "Frontal face capture is required."}), 400

        frontal_bgr = decode_base64_image(frontal_data)
        if frontal_bgr is None:
            return jsonify({"success": False, "message": "Could not decode frontal face image."}), 400

        pose_list = []
        for p_data in [left_data, right_data, liveness_data]:
            if p_data:
                p_bgr = decode_base64_image(p_data)
                if p_bgr is not None:
                    pose_list.append(p_bgr)

        # Also support poses list if sent as an array
        poses_array = data.get('poses')
        if isinstance(poses_array, list):
            for p_item in poses_array:
                if p_item and isinstance(p_item, str):
                    p_bgr = decode_base64_image(p_item)
                    if p_bgr is not None:
                        pose_list.append(p_bgr)

        try:
            record, photo_filename = engine.enroll_multi_pose(
                user_id=emp["userId"],
                name=user_name,
                frontal_bgr=frontal_bgr,
                pose_bgr_list=pose_list,
                metadata={
                    "role": user_role,
                    "designation": designation,
                    "expected_in": expected_in,
                    "verified_via": "3D_motion_capture",
                    "pose_count": 1 + len(pose_list)
                }
            )

            db.update_user_profile_photo(emp["userId"], photo_filename)

            return jsonify({
                "success": True,
                "user_id": emp["userId"],
                "name": user_name,
                "role": user_role,
                "photo_filename": photo_filename,
                "pose_count": 1 + len(pose_list),
                "total_registered": len(engine.db),
                "message": f"Biometric face verified and registered successfully for {user_name}!"
            })
        except ValueError as ve:
            return jsonify({"success": False, "message": str(ve)}), 400

    except Exception as e:
        print(f"[!] verify_and_register_face error: {e}")
        return jsonify({"success": False, "error": str(e)}), 500


@app.route('/api/scan_face', methods=['POST'])
def scan_face():
    try:
        data = request.get_json(silent=True) or {}
        image_data = data.get('image') or request.form.get('image')
        
        if not image_data:
            return jsonify({"success": False, "message": "No image data received"}), 400

        # Decode base64 image
        if 'base64,' in image_data:
            image_data = image_data.split('base64,')[1]

        image_bytes = base64.b64decode(image_data)
        np_arr = np.frombuffer(image_bytes, np.uint8)
        frame_bgr = cv2.imdecode(np_arr, cv2.IMREAD_COLOR)

        if frame_bgr is None:
            return jsonify({"success": False, "message": "Could not decode image frame"}), 400

        # Run face detection and recognition with FaceVision AI engine
        target_user_id = data.get('target_user_id') or request.form.get('target_user_id')
        match_result = engine.match_face(frame_bgr, target_user_id=target_user_id)

        # Save debug snapshot of last detected frame to inspect camera view
        if match_result.get("detected"):
            try:
                debug_dir = os.path.join(os.path.dirname(__file__), "..", "download")
                os.makedirs(debug_dir, exist_ok=True)
                cv2.imwrite(os.path.join(debug_dir, "last_detected_frame.jpg"), frame_bgr)
            except Exception:
                pass

        print(f"[*] Scan target={target_user_id}: detected={match_result.get('detected')}, rec={match_result.get('recognized')}, dist={match_result.get('distance')}, sim={match_result.get('similarity')}%")

        if not match_result.get("detected"):
            return jsonify({
                "success": False,
                "detected": False,
                "message": match_result.get("message", "No face detected")
            })

        if not match_result.get("recognized"):
            return jsonify({
                "success": False,
                "detected": True,
                "recognized": False,
                "similarity": match_result.get("similarity", 0),
                "distance": match_result.get("distance"),
                "box": match_result.get("box"),
                "message": match_result.get("message", f"Face detected ({match_result.get('similarity', 0)}%) but not recognized")
            })

        user_id = match_result["user_id"]
        user_name = match_result["name"]
        role = match_result.get("role", "")
        expected_in = match_result.get("expected_in", "09:30:00")
        similarity = match_result.get("similarity", 0)
        distance = match_result.get("distance", 0)
        box = match_result.get("box")

        # Check anti-repetition cooldown
        now = time.time()
        last_punch = user_cooldowns.get(user_id, 0)
        if (now - last_punch) < COOLDOWN_SECONDS:
            remaining = int(COOLDOWN_SECONDS - (now - last_punch))
            return jsonify({
                "success": True,
                "detected": True,
                "recognized": True,
                "cooldown": True,
                "user_id": user_id,
                "user_name": user_name,
                "similarity": similarity,
                "distance": distance,
                "box": box,
                "message": f"Attendance already processed for {user_name}! Please step aside (cooldown: {remaining}s)."
            })

        # Save snapshot for audit
        snapshot_filename = f"live_scan_{user_id}_{int(now)}.jpg"
        snapshot_path = os.path.join(os.path.dirname(__file__), "..", "download", snapshot_filename)
        try:
            cv2.imwrite(snapshot_path, frame_bgr)
        except Exception:
            snapshot_filename = "live_ai_scan"

        # Mark attendance directly in MySQL database with GPS location
        latitude = data.get('latitude', 0.0)
        longitude = data.get('longitude', 0.0)
        att_res = db.mark_attendance(
            user_id=user_id,
            employee_name=user_name,
            user_role=role,
            expected_in=expected_in,
            image_name=snapshot_filename,
            latitude=latitude,
            longitude=longitude
        )

        # Set cooldown
        user_cooldowns[user_id] = now

        return jsonify({
            "success": True,
            "detected": True,
            "recognized": True,
            "cooldown": False,
            "user_id": user_id,
            "user_name": user_name,
            "similarity": similarity,
            "distance": distance,
            "box": box,
            "action": att_res.get("action"),
            "time": att_res.get("time"),
            "date": att_res.get("date"),
            "man_hours": att_res.get("man_hours"),
            "message": att_res.get("message")
        })

    except Exception as e:
        print(f"[!] scan_face error: {e}")
        return jsonify({"success": False, "error": str(e)}), 500

if __name__ == '__main__':
    print("[*] Starting SETTribe Face Attendance AI Service on http://0.0.0.0:5001")
    app.run(host='0.0.0.0', port=5001, debug=False)
