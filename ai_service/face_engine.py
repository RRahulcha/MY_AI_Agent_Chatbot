import os
import json
import pickle
import threading
import cv2
import numpy as np
from deepface import DeepFace

class FaceEngine:
    """
    FaceVision AI Engine for SETTribe AMS:
    1. Face Detection & Crop (OpenCV Haar Cascade)
    2. Face Alignment & Embedding Extraction (DeepFace Facenet, L2 Normalized)
    3. Structured JSON Database Storage (face_db.json)
    4. Strict Vector Matching via Cosine Distance with Target Verification
    """
    def __init__(self, photo_dir=None, db_file=None, cache_file=None, model_name="Facenet512", threshold=0.46):
        base_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
        self.photo_dir = photo_dir or os.path.join(base_dir, "profile-photos")
        self.db_file = db_file or os.path.join(os.path.dirname(__file__), "face_db.json")
        self.cache_file = cache_file or os.path.join(os.path.dirname(__file__), "encodings_cache.pkl")
        self.cascade_path = os.path.join(os.path.dirname(__file__), "models", "haarcascade_frontalface_default.xml")
        
        self.model_name = model_name
        self.threshold = threshold  # Biometric Cosine distance threshold (<= 0.46)
        self.similarity_threshold = round((( (1.0 - self.threshold) - 0.35) / 0.65) * 100.0, 1)
        
        # Load Haar Cascade
        if not os.path.exists(self.cascade_path):
            self.cascade_path = cv2.data.haarcascades + 'haarcascade_frontalface_default.xml'
        self.face_cascade = cv2.CascadeClassifier(self.cascade_path)
        self._cascade_lock = threading.Lock()
        
        # In-memory database of registered faces:
        # [
        #   {
        #     "id": int,
        #     "user_id": str,
        #     "name": str,
        #     "image_file": str,
        #     "metadata": {"role": str, "expected_in": str, "designation": str},
        #     "embedding": list of floats
        #   }
        # ]
        self.db = []
        self.load_db()

    # =========================================================================
    # STAGE 1: FACE DETECTION & CROPPING (High-Speed Downscaled Haar Cascade)
    # =========================================================================
    def detect_and_crop(self, image_bgr, pad_ratio=0.10):
        """
        Detect faces using downscaled Haar Cascade (takes ~15ms instead of ~270ms)
        and return cropped face regions with slight padding mapped to full resolution.
        """
        if image_bgr is None or image_bgr.size == 0:
            return []

        h_img, w_img = image_bgr.shape[:2]

        # Downscale for ultra-fast face detection if frame is larger than 320px
        scale = 1.0
        if w_img > 320:
            scale = 320.0 / w_img
            small_bgr = cv2.resize(image_bgr, (0, 0), fx=scale, fy=scale, interpolation=cv2.INTER_AREA)
        else:
            small_bgr = image_bgr

        with self._cascade_lock:
            gray = cv2.cvtColor(small_bgr, cv2.COLOR_BGR2GRAY)
            # Primary pass
            faces_detected = self.face_cascade.detectMultiScale(
                gray, 
                scaleFactor=1.1, 
                minNeighbors=4, 
                minSize=(25, 25)
            )
            # Fallback pass for softer edges / distant faces
            if len(faces_detected) == 0:
                faces_detected = self.face_cascade.detectMultiScale(
                    gray, 
                    scaleFactor=1.2, 
                    minNeighbors=3, 
                    minSize=(20, 20)
                )

        crops = []
        inv_scale = 1.0 / scale
        for (sx, sy, sw, sh) in faces_detected:
            x = int(round(sx * inv_scale))
            y = int(round(sy * inv_scale))
            w = int(round(sw * inv_scale))
            h = int(round(sh * inv_scale))

            pad_w = int(w * pad_ratio)
            pad_h = int(h * pad_ratio)
            x1 = max(0, x - pad_w)
            y1 = max(0, y - pad_h)
            x2 = min(w_img, x + w + pad_w)
            y2 = min(h_img, y + h + pad_h)
            crop = image_bgr[y1:y2, x1:x2]
            if crop.size > 0:
                crops.append({
                    "box": (int(x), int(y), int(w), int(h)),
                    "face_crop": crop,
                    "area": int(w * h)
                })

        # Sort largest face first
        crops.sort(key=lambda c: c["area"], reverse=True)
        return crops

    # =========================================================================
    # STAGE 2: FEATURE EMBEDDING EXTRACTION (Direct Skip Backend ~230ms)
    # =========================================================================
    def get_embedding(self, face_img):
        """
        Extract 512-D L2-normalized biometric embedding vector using Facenet512.
        Ultra-fast inference (detector_backend='skip', align=False) ~230ms.
        """
        try:
            # Resize crop to 160x160 (Facenet native input) for fastest preprocessing
            if face_img.shape[0] != 160 or face_img.shape[1] != 160:
                face_resized = cv2.resize(face_img, (160, 160), interpolation=cv2.INTER_LINEAR)
            else:
                face_resized = face_img

            rep = DeepFace.represent(
                img_path=face_resized,
                model_name=self.model_name,
                detector_backend="skip",
                enforce_detection=False,
                align=False
            )
            if not rep or len(rep) == 0:
                return None
            
            emb = np.array(rep[0]["embedding"], dtype=np.float32)
            norm = np.linalg.norm(emb)
            if norm > 0:
                emb = emb / norm
            return emb.tolist()
        except Exception as e:
            print(f"[!] Embedding extraction error: {e}")
            return None

    # =========================================================================
    # STAGE 3: DATABASE STORAGE & ENROLLMENT (face_db.json)
    # =========================================================================
    def load_db(self):
        """Load registered face embeddings from face_db.json"""
        if os.path.exists(self.db_file):
            try:
                with open(self.db_file, "r", encoding="utf-8") as f:
                    self.db = json.load(f)
                print(f"[OK] Loaded {len(self.db)} registered employee faces from face_db.json")
                return True
            except Exception as e:
                print(f"[!] Error loading {self.db_file}: {e}")

        # Fallback to pickle cache if JSON not yet created
        if os.path.exists(self.cache_file):
            try:
                with open(self.cache_file, "rb") as f:
                    raw_faces = pickle.load(f)
                converted = []
                for idx, r in enumerate(raw_faces, 1):
                    # Filter out placeholder/default embeddings
                    photo = r.get("photo_file", "")
                    if "default" in photo.lower():
                        continue
                    emb = r.get("embedding")
                    if emb is not None:
                        emb_list = emb.tolist() if isinstance(emb, np.ndarray) else emb
                        converted.append({
                            "id": idx,
                            "user_id": str(r.get("user_id")),
                            "name": r.get("name"),
                            "image_file": photo,
                            "metadata": {
                                "role": r.get("role", ""),
                                "expected_in": r.get("expected_in", "09:30:00")
                            },
                            "embedding": [round(float(x), 6) for x in emb_list]
                        })
                self.db = converted
                self.save_db()
                print(f"[OK] Migrated {len(self.db)} clean faces into face_db.json")
                return True
            except Exception as e:
                print(f"[!] Error migrating pickle cache: {e}")

        self.db = []
        return False

    def save_db(self):
        """Persist registered faces to face_db.json & update pickle for compatibility"""
        with open(self.db_file, "w", encoding="utf-8") as f:
            json.dump(self.db, f, indent=2)

        # Also write pickle cache for backward-compatibility
        compat_cache = []
        for r in self.db:
            compat_cache.append({
                "user_id": r["user_id"],
                "name": r["name"],
                "photo_file": r["image_file"],
                "role": r.get("metadata", {}).get("role", ""),
                "expected_in": r.get("metadata", {}).get("expected_in", "09:30:00"),
                "embedding": np.array(r["embedding"], dtype=np.float32)
            })
        with open(self.cache_file, "wb") as f:
            pickle.dump(compat_cache, f)

    @property
    def known_faces(self):
        """Compatibility property for existing callers"""
        return self.db

    def enroll_person(self, user_id, name, image_path, metadata=None):
        """
        Enroll a single employee:
        1. Validates real face exists in photo
        2. Crops & extracts normalized embedding
        3. Saves to face_db.json
        """
        user_id = str(user_id)
        if not os.path.exists(image_path):
            raise FileNotFoundError(f"Image not found: {image_path}")

        img_bgr = cv2.imread(image_path)
        if img_bgr is None:
            raise ValueError(f"Could not read image file: {image_path}")

        faces = self.detect_and_crop(img_bgr)
        if not faces:
            raise ValueError(f"No face detected in photo: {image_path}. Enrollment rejected.")

        face_crop = faces[0]["face_crop"]
        emb = self.get_embedding(face_crop)
        if emb is None:
            raise ValueError(f"Failed to extract facial embedding for: {name}")

        # Remove existing user if re-enrolling
        self.db = [r for r in self.db if str(r.get("user_id")) != user_id]

        new_record = {
            "id": len(self.db) + 1,
            "user_id": user_id,
            "name": name,
            "image_file": os.path.basename(image_path),
            "metadata": metadata or {},
            "embedding": [round(float(x), 6) for x in emb]
        }
        self.db.append(new_record)
        self.save_db()
        print(f"[OK] Successfully enrolled: {name} (User ID: {user_id})")
        return new_record

    def enroll_multi_pose(self, user_id, name, frontal_bgr, pose_bgr_list=None, metadata=None):
        """
        Enroll employee using 3D multi-pose motion verification:
        1. Frontal frame: primary embedding & profile photo.
        2. Optional Left/Right/Smile frames: computes embeddings and blends
           a robust multi-angle composite embedding.
        3. Saves cropped frontal photo to profile-photos/{user_id}_{timestamp}.png.
        4. Updates face_db.json.
        """
        import time
        user_id = str(user_id)
        if frontal_bgr is None or frontal_bgr.size == 0:
            raise ValueError("Frontal face frame is required.")

        # Detect face in frontal frame
        frontal_crops = self.detect_and_crop(frontal_bgr)
        if not frontal_crops:
            raise ValueError("No clear frontal face detected. Please face the camera directly.")

        frontal_face_crop = frontal_crops[0]["face_crop"]
        primary_emb = self.get_embedding(frontal_face_crop)
        if primary_emb is None:
            raise ValueError(f"Could not compute facial biometric features for {name}.")

        all_embs = [np.array(primary_emb, dtype=np.float32)]

        # Process additional poses (Left, Right, Liveness)
        if pose_bgr_list:
            for p_img in pose_bgr_list:
                if p_img is not None and p_img.size > 0:
                    p_crops = self.detect_and_crop(p_img)
                    if p_crops:
                        p_emb = self.get_embedding(p_crops[0]["face_crop"])
                        if p_emb is not None:
                            all_embs.append(np.array(p_emb, dtype=np.float32))

        # Compute averaged, L2-normalized composite embedding (weighing frontal 50%)
        if len(all_embs) > 1:
            weights = [2.0] + [1.0] * (len(all_embs) - 1)
            stacked = np.average(all_embs, axis=0, weights=weights)
            norm = np.linalg.norm(stacked)
            if norm > 0:
                final_emb = (stacked / norm).tolist()
            else:
                final_emb = primary_emb
        else:
            final_emb = primary_emb

        # Save photo file to profile-photos
        photo_filename = f"{user_id}_{int(time.time())}.png"
        photo_path = os.path.join(self.photo_dir, photo_filename)
        cv2.imwrite(photo_path, frontal_bgr)

        # Remove existing user if re-enrolling
        self.db = [r for r in self.db if str(r.get("user_id")) != user_id]

        all_embs_list = [[round(float(x), 6) for x in emb] for emb in all_embs]

        new_record = {
            "id": len(self.db) + 1,
            "user_id": user_id,
            "name": name,
            "image_file": photo_filename,
            "metadata": metadata or {},
            "embedding": [round(float(x), 6) for x in final_emb],
            "all_embeddings": all_embs_list
        }
        self.db.append(new_record)
        self.save_db()
        print(f"[OK] Multi-pose 3D motion enrollment successful: {name} ({user_id}) with {len(all_embs)} angles.")
        return new_record, photo_filename

    def build_cache(self, employee_records):
        """
        Index all active employees from the MySQL database:
        - Skips placeholder/default photos
        - Skips photos where Haar Cascade cannot detect a genuine face
        - Extracts and saves clean embeddings into face_db.json
        """
        print(f"[*] Building face embeddings database from {len(employee_records)} employee records...")
        new_db = []
        skipped_count = 0

        for emp in employee_records:
            user_id = str(emp.get('userId'))
            name = emp.get('fullName') or emp.get('username') or user_id
            photo_name = emp.get('profilePhoto', '')

            if not photo_name or 'default' in photo_name.lower():
                skipped_count += 1
                continue

            photo_filename = os.path.basename(photo_name)
            photo_path = os.path.join(self.photo_dir, photo_filename)

            if not os.path.exists(photo_path):
                alt_path = os.path.join(self.photo_dir, photo_name)
                if os.path.exists(alt_path):
                    photo_path = alt_path
                else:
                    skipped_count += 1
                    continue

            img_bgr = cv2.imread(photo_path)
            if img_bgr is None:
                skipped_count += 1
                continue

            # Detect genuine face in photo
            faces = self.detect_and_crop(img_bgr)
            if not faces:
                print(f"  [!] Skipped {name} ({photo_filename}): No face detected in profile photo.")
                skipped_count += 1
                continue

            face_crop = faces[0]["face_crop"]
            emb = self.get_embedding(face_crop)
            if emb is None:
                skipped_count += 1
                continue

            new_db.append({
                "id": len(new_db) + 1,
                "user_id": user_id,
                "name": name,
                "image_file": photo_filename,
                "metadata": {
                    "role": emp.get('userRole') or '',
                    "designation": emp.get('designation') or '',
                    "expected_in": str(emp.get('expectedPunchIn') or '09:30:00')
                },
                "embedding": [round(float(x), 6) for x in emb]
            })
            print(f"  [+] Enrolled: {name} (User ID: {user_id})")

        self.db = new_db
        self.save_db()
        print(f"[OK] Finished enrollment! {len(self.db)} genuine faces enrolled ({skipped_count} skipped/invalid).")

    # =========================================================================
    # STAGE 4: VECTOR MATCHING & RECOGNITION (Cosine Distance)
    # =========================================================================
    def detect_faces_fast(self, frame_bgr):
        """Fast bounding box detection for camera preview overlays"""
        crops = self.detect_and_crop(frame_bgr)
        return [c["box"] for c in crops]

    def match_face(self, input_img, target_user_id=None):
        """
        Face Recognition & Matching:
        - If target_user_id is provided (self-service attendance):
          Verifies ONLY against that user's enrolled face. NEVER matches another colleague!
        - If target_user_id is None (kiosk mode):
          Finds the best matching enrolled employee if distance <= threshold.
        
        Returns:
            {
                "detected": bool,
                "recognized": bool,
                "user_id": str or None,
                "name": str or None,
                "role": str or None,
                "expected_in": str or None,
                "similarity": float, # 0 - 100%
                "distance": float,
                "box": [x, y, w, h] or None,
                "message": str
            }
        """
        if not self.db:
            return {
                "detected": False, 
                "recognized": False, 
                "message": "No registered faces in face_db.json. Please enroll employee photos first."
            }

        if isinstance(input_img, str):
            frame_bgr = cv2.imread(input_img)
        else:
            frame_bgr = input_img

        if frame_bgr is None:
            return {"detected": False, "recognized": False, "message": "Invalid image"}

        # 1. Detect & Crop Face
        crops = self.detect_and_crop(frame_bgr)
        if not crops:
            return {"detected": False, "recognized": False, "message": "No face detected in camera"}

        best_face = crops[0]
        x, y, w, h = best_face["box"]
        face_crop = best_face["face_crop"]

        # 2. Extract Query Embedding
        query_emb_list = self.get_embedding(face_crop)
        if not query_emb_list:
            return {
                "detected": True, 
                "recognized": False, 
                "box": [x, y, w, h], 
                "message": "Could not compute facial features"
            }

        query_emb = np.array(query_emb_list, dtype=np.float32)

        # Helper function to compute Cosine Distance and similarity percentage
        def calculate_metrics(target_emb, ref_emb):
            # Cosine similarity = dot product of L2-normalized vectors (between -1.0 and 1.0)
            cos_sim = float(np.dot(target_emb, ref_emb))
            # Cosine distance = 1 - Cosine Similarity
            cos_dist = max(0.0, 1.0 - cos_sim)
            # Normalized biometric confidence percentage
            sim_pct = round(max(0.0, min(100.0, ((cos_sim - 0.35) / (1.0 - 0.35)) * 100.0)), 1)
            return cos_dist, sim_pct

        # =====================================================================
        # CASE A: TARGET USER SPECIFIED (Personal Attendance / captureSelfie.php)
        # Check all candidate embeddings for this employee (ID and Name aliases)
        # =====================================================================
        if target_user_id:
            target_records = [r for r in self.db if str(r.get("user_id")) == str(target_user_id)]
            if target_records:
                target_name_lower = target_records[0].get("name", "").strip().lower()
                for r in self.db:
                    if r not in target_records and target_name_lower:
                        r_name_lower = r.get("name", "").strip().lower()
                        if target_name_lower in r_name_lower or r_name_lower in target_name_lower:
                            target_records.append(r)
            else:
                for r in self.db:
                    if str(target_user_id).lower() in str(r.get("name", "")).lower():
                        target_records.append(r)

            if not target_records:
                return {
                    "detected": True,
                    "recognized": False,
                    "box": [x, y, w, h],
                    "message": f"User ID {target_user_id} has no registered face profile in face_db.json."
                }

            best_rec = None
            best_dist = float("inf")
            best_sim_pct = 0.0

            for rec in target_records:
                candidates = [rec["embedding"]]
                if "all_embeddings" in rec and isinstance(rec["all_embeddings"], list):
                    candidates.extend(rec["all_embeddings"])

                for cand in candidates:
                    ref_emb = np.array(cand, dtype=np.float32)
                    dist, sim_pct = calculate_metrics(query_emb, ref_emb)
                    if dist < best_dist:
                        best_dist = dist
                        best_sim_pct = sim_pct
                        best_rec = rec

            is_match = (best_dist <= self.threshold)
            meta = best_rec.get("metadata", {})

            if is_match:
                return {
                    "detected": True,
                    "recognized": True,
                    "user_id": best_rec["user_id"],
                    "name": best_rec["name"],
                    "role": meta.get("role", ""),
                    "expected_in": meta.get("expected_in", "09:30:00"),
                    "similarity": best_sim_pct,
                    "distance": round(best_dist, 4),
                    "box": [x, y, w, h],
                    "message": f"Verified {best_rec['name']} (Match: {best_sim_pct}%)"
                }
            else:
                if best_sim_pct < 20.0:
                    status_msg = "Scanning face... Please look directly at the camera"
                elif best_sim_pct < 35.0:
                    status_msg = "Position your face inside the bracket & hold steady"
                else:
                    status_msg = f"Verifying identity... Please hold still ({best_sim_pct}%)"

                return {
                    "detected": True,
                    "recognized": False,
                    "user_id": best_rec["user_id"],
                    "similarity": best_sim_pct,
                    "distance": round(best_dist, 4),
                    "box": [x, y, w, h],
                    "message": status_msg
                }

        # =====================================================================
        # CASE B: KIOSK MODE (Identify who is standing in front of the camera)
        # =====================================================================
        best_match = None
        min_dist = float("inf")
        best_sim_pct = 0.0

        for record in self.db:
            candidates = [record["embedding"]]
            if "all_embeddings" in record and isinstance(record["all_embeddings"], list):
                candidates.extend(record["all_embeddings"])

            for cand in candidates:
                ref_emb = np.array(cand, dtype=np.float32)
                dist, sim_pct = calculate_metrics(query_emb, ref_emb)

                if dist < min_dist:
                    min_dist = dist
                    best_match = record
                    best_sim_pct = sim_pct

        if best_match and min_dist <= self.threshold:
            meta = best_match.get("metadata", {})
            return {
                "detected": True,
                "recognized": True,
                "user_id": best_match["user_id"],
                "name": best_match["name"],
                "role": meta.get("role", ""),
                "expected_in": meta.get("expected_in", "09:30:00"),
                "similarity": best_sim_pct,
                "distance": round(min_dist, 4),
                "box": [x, y, w, h],
                "message": f"Matched {best_match['name']} ({best_sim_pct}%)"
            }
        else:
            if best_sim_pct < 20.0:
                kiosk_msg = "Scanning face... Please look directly at the camera"
            elif best_sim_pct < 35.0:
                kiosk_msg = "Position your face inside the bracket & hold steady"
            else:
                kiosk_msg = f"Verifying identity... Please hold still ({best_sim_pct}%)"

            return {
                "detected": True,
                "recognized": False,
                "name": "Unknown",
                "similarity": best_sim_pct,
                "distance": round(min_dist, 4) if min_dist != float("inf") else 1.0,
                "box": [x, y, w, h],
                "message": kiosk_msg
            }
