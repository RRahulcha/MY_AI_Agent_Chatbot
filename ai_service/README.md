# 👁️ FaceVision AI Biometric Engine (`ai_service`)
### *High-Speed Facial Verification & 3D Motion Anti-Spoofing Service*

[![Python](https://img.shields.io/badge/Python-3.10+-3776AB?style=for-the-badge&logo=python&logoColor=white)](https://python.org)
[![DeepFace](https://img.shields.io/badge/DeepFace-Facenet512-FF6F00?style=for-the-badge&logo=tensorflow&logoColor=white)](https://github.com/serengil/deepface)
[![OpenCV](https://img.shields.io/badge/OpenCV-Haar_Cascade-5C3EE8?style=for-the-badge&logo=opencv&logoColor=white)](https://opencv.org)
[![Flask](https://img.shields.io/badge/Flask-REST_Microservice-000000?style=for-the-badge&logo=flask&logoColor=white)](https://flask.palletsprojects.com)

---

## 📖 Overview

The **FaceVision AI** biometric service is an optimized asynchronous Python microservice running on port **5001**. It powers the proxy-free biometric attendance workflows in SETTribe AMS by combining ultra-fast OpenCV Haar Cascade face detection with DeepFace Facenet512 feature representations.

---

## ⚡ The 4-Stage Verification Pipeline

```mermaid
flowchart LR
    Frame["Input Frame (Base64 JPEG)"] --> S1["Stage 1: Downscaled Haar (~15ms)"]
    S1 --> S2["Stage 2: 10% Margin Crop & 160x160 Rescale (~35ms)"]
    S2 --> S3["Stage 3: DeepFace Facenet512 Embeddings (~230ms)"]
    S3 --> S4{"Stage 4: Cosine Metric\nDistance <= 0.26?"}
    S4 -- "Yes (Similarity >= 48%)" --> Cooldown["Anti-Repetition Cooldown (15s)"]
    S4 -- "No" --> Reject["Reject / Prompt Retake"]
```

1. **Stage 1: Downscaled Haar Face Detection (~15ms)**
   - Rescales incoming webcam stream to max 320px width before executing Haar cascade analysis, dropping detection overhead from ~270ms down to ~15ms.
2. **Stage 2: 10% Margin Cropping & Tensor Rescaling**
   - Automatically adds an outer 10% padding margin around detected face bounds and resizes directly to `(160, 160)` tensors, eliminating costly alignment steps.
3. **Stage 3: 512-Dimensional Vector Generation**
   - Extracts 512 floating-point values using `Facenet512` and normalizes using L2 norm.
4. **Stage 4: Cosine Distance & Session Locking**
   - Compares the generated vector against the employee's registered vector stored in `face_db.json`.
   - **Verification Threshold**: Cosine Distance $\le 0.26$ ($\ge 48.0\%$ similarity).
   - Enforces a 15-second per-employee cooldown in memory to eliminate accidental double punch-ins.

---

## 📡 REST API Reference

The service operates on `http://127.0.0.1:5001`:

| Endpoint | Method | Description |
| :--- | :---: | :--- |
| `/api/status` | `GET` | Microservice health, loaded model status, and total registered face count. |
| `/api/scan_face` | `POST` | Validates webcam snapshot against session user ID; returns verification score and commit status. |
| `/api/verify_and_register_face` | `POST` | Processes multi-angle face verification captures during enrollment. |
| `/api/enroll_face` | `POST` | Extracts facial embedding from uploaded employee profile photo and updates in-memory cache. |
| `/api/reload_cache` | `POST` | Force re-sync of all active employee facial vectors from MySQL database. |

---

## 🛠️ Maintenance & Database Rebuild

To rebuild or re-index the facial vector cache from employee profile photos:

```bash
cd ai_service
python rebuild_db.py
```
