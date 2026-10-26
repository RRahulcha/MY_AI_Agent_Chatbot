FROM python:3.10-slim

# Set environment variables
ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    DEEPFACE_HOME=/root/.deepface

# Install system dependencies required for OpenCV and FaceVision
RUN apt-get update && apt-get install -y --no-install-recommends \
    libgl1 \
    libglib2.0-0 \
    curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Cache dependencies layer
COPY ai_service/requirements.txt /app/ai_service/requirements.txt
RUN pip install --no-cache-dir --upgrade pip \
    && pip install --no-cache-dir -r /app/ai_service/requirements.txt

# Copy project files
COPY . /app

WORKDIR /app/ai_service

EXPOSE 5001

# Health check to verify service is ready on port 5001
HEALTHCHECK --interval=15s --timeout=5s --start-period=35s --retries=5 \
    CMD curl -f http://localhost:5001/api/status || exit 1

CMD ["python", "-u", "app.py"]
