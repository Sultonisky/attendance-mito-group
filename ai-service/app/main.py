import logging

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse

from app.api.face import router as face_router
from app.api.health import router as health_router
from app.core.config import get_settings

settings = get_settings()

logging.basicConfig(
    level=logging.DEBUG if settings.debug else logging.INFO,
)

app = FastAPI(
    title="Attendance AI Service",
    version="1.0.0",
)

app.include_router(health_router)
app.include_router(face_router)


@app.exception_handler(Exception)
async def unhandled_exception_handler(request: Request, exc: Exception) -> JSONResponse:
    """Never leak stack traces or internals to API clients."""
    logging.exception("Unhandled exception while handling %s %s", request.method, request.url.path)

    return JSONResponse(
        status_code=500,
        content={
            "status": "error",
            "message": "Internal server error.",
        },
    )
