import os
import logging
import aioboto3
from app.config import settings

logger = logging.getLogger("ams-ai.storage.r2")

LOCAL_UPLOAD_DIR = os.path.abspath(os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "uploads"))

class R2StorageManager:
    def __init__(self):
        self.endpoint_url = settings.R2_ENDPOINT
        self.access_key = settings.R2_ACCESS_KEY
        self.secret_key = settings.R2_SECRET_KEY
        self.bucket_name = settings.R2_BUCKET
        
        self.simulate = not (self.endpoint_url and self.access_key and self.secret_key)
        
        if self.simulate:
            logger.warning("R2 credentials not fully configured. Running in Local Storage simulation mode.")
            os.makedirs(LOCAL_UPLOAD_DIR, exist_ok=True)
        else:
            self.session = aioboto3.Session()

    async def upload_file(self, file_content: bytes, file_name: str, folder: str = "documents") -> str:
        """
        Upload file data to Cloudflare R2 bucket (or local filesystem if in simulation).
        
        Returns the accessible file path or public URL.
        """
        object_key = f"{folder}/{file_name}"
        
        if self.simulate:
            # Local simulator fallback
            local_path = os.path.join(LOCAL_UPLOAD_DIR, folder, file_name)
            os.makedirs(os.path.dirname(local_path), exist_ok=True)
            with open(local_path, "wb") as f:
                f.write(file_content)
            logger.info(f"File uploaded locally to: {local_path}")
            return f"uploads/{folder}/{file_name}"
            
        else:
            # Upload to Cloudflare R2
            try:
                async with self.session.client(
                    service_name="s3",
                    endpoint_url=self.endpoint_url,
                    aws_access_key_id=self.access_key,
                    aws_secret_access_key=self.secret_key,
                    region_name="auto"
                ) as client:
                    await client.put_object(
                        Bucket=self.bucket_name,
                        Key=object_key,
                        Body=file_content,
                        ACL="public-read"
                    )
                logger.info(f"File uploaded to R2 bucket: {object_key}")
                return f"{self.endpoint_url.rstrip('/')}/{self.bucket_name}/{object_key}"
            except Exception as e:
                logger.error(f"Failed to upload file to Cloudflare R2: {e}")
                raise e

# Singleton instance
r2_storage = R2StorageManager()
