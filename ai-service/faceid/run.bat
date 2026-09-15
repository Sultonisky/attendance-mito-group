@echo off
cd /d "%~dp0"
echo FaceID Lab -> http://localhost:8090
python -m uvicorn app:app --host 0.0.0.0 --port 8090