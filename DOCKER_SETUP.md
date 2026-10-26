# 🐳 Docker Setup Guide for SETTribe AMS

Complete guide to run **SETTribe AMS (PHP Web + Python FaceVision AI Microservice)** on any laptop or server using Docker.

---

## 📋 Prerequisites on the Target Laptop

1. **Docker Desktop**:
   - [Download Docker Desktop](https://www.docker.com/products/docker-desktop/) (Windows / macOS / Linux).
   - Ensure Docker Desktop is running before executing commands.
2. **Git**:
   - To clone the repository.

---

## ⚡ Quick Start (3 Steps)

### Step 1: Clone the Repository
```bash
git clone https://github.com/SETTribe-IT-Solutions/Dev-SETTribe-AMS.git
cd Dev-SETTribe-AMS
git checkout tusharsingh
```

### Step 2: Setup Environment File (`.env`)
Create a `.env` file in the project root (or copy `.env.example`):
```bash
cp .env.example .env
```
Ensure your database credentials are in `.env`:
```ini
PORT=8080
AI_SERVICE_URL=http://ai_service:5001

# Database Configuration (Remote MySQL)
DB_HOST=103.160.107.18
DB_NAME=nmrmlatur_settribe_ams
DB_USER=nmrmlatur_settribe_ams
DB_PASS=SETTribe@ams1821
DB_PORT=3306

# Or use full DATABASE_URL
# DATABASE_URL=mysql://nmrmlatur_settribe_ams:SETTribe%40ams1821@103.160.107.18:3306/nmrmlatur_settribe_ams
```

### Step 3: Build & Start Containers
Run in the project root directory:
```bash
docker compose up --build -d
```

---

## 🌐 Accessing the Application

Once the containers are running:

| Service | URL |
| :--- | :--- |
| **Login Page** | [http://localhost:8080/pages/login.php](http://localhost:8080/pages/login.php) |
| **Live Face Attendance** | [http://localhost:8080/pages/live_face_attendance.php](http://localhost:8080/pages/live_face_attendance.php) |
| **3D Motion Face Verification** | [http://localhost:8080/pages/face_verification.php](http://localhost:8080/pages/face_verification.php) |
| **Python AI Microservice API** | [http://localhost:5001/api/status](http://localhost:5001/api/status) |

---

## 🛠️ Useful Docker Commands

### Check Running Containers
```bash
docker compose ps
```

### View Live Logs
```bash
# All logs
docker compose logs -f

# Web PHP logs only
docker compose logs -f web

# AI service logs only
docker compose logs -f ai_service
```

### Rebuild Faces Database Inside Container
```bash
docker compose exec ai_service python rebuild_db.py
```

### Stop Containers
```bash
docker compose down
```

### Restart Containers
```bash
docker compose restart
```

---

## 💡 Troubleshooting

1. **Port 8080 already in use?**
   Change `PORT=8080` to another port (e.g. `PORT=8090`) in `.env` and rerun:
   ```bash
   docker compose up -d
   ```
2. **AI Service status is offline?**
   On first launch, DeepFace downloads the Facenet512 weights model (~100 MB). Check the logs:
   ```bash
   docker compose logs -f ai_service
   ```
   Once warmed up, `/api/status` will return `{"status": "online"}`.
