from fastapi import FastAPI

app = FastAPI(
    title="Attendance AI Service",
    version="1.0.0",
)


@app.get("/health")
def health():
    return {
        "status": "ok",
        "service": "ai-service",
    }