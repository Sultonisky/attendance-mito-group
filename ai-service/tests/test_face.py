"""
Tests for the FastAPI face AI/CV endpoints.

These tests use TestClient against the actual FastAPI application. They validate:
- API key authentication (missing/invalid/valid)
- Valid image enrollment via MitoAiEngine
- Invalid image rejection (bad format, oversized, empty)
- Idempotency (same key + same image returns same reference)
- Idempotency conflict (same key + different image returns 409)
- No employee identity in request or response
- No raw embedding in response
- Verification response schema
- Liveness field presence and safe default
- Malformed request handling
- Exception handling boundary
"""

import hashlib
import io
import os
import unittest.mock

# Set the API key BEFORE importing app modules so the cached Settings
# picks it up on first access.
os.environ["AI_API_KEY"] = "test-secret-key-123"

import pytest
from fastapi.testclient import TestClient
from PIL import Image

from app.ai.engine.mito_ai_engine import MitoAiResult, MitoAiError, MultipleFacesDetectedError, NoFaceDetectedError
from app.ai.liveness.mini_fasnet import LivenessResult
from app.ai.quality.face_quality import QualityResult
from app.core.config import get_settings
from app.core.face import FaceResult
from app.main import app

# Clear the settings cache so the env var above takes effect.
get_settings.cache_clear()

client = TestClient(app)

_TEST_API_KEY = "test-secret-key-123"


def _make_test_image(
    size: tuple[int, int] = (64, 64),
    seed: int = 0,
) -> bytes:
    """Generate a small in-memory PNG image with visual variance.

    ``seed`` controls the pattern offset so different calls produce genuinely
    different pixel content.
    """
    img = Image.new("RGB", size, color=(255, 255, 255))
    for x in range(size[0]):
        for y in range(size[1]):
            img.putpixel(
                (x, y),
                (
                    ((x * 7 + seed * 13) % 256),
                    ((y * 13 + seed * 7) % 256),
                    ((x + y + seed * 3) * 3 % 256),
                ),
            )
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    return buf.getvalue()


def _png_bytes(data: bytes) -> tuple[bytes, str]:
    """Return (bytes, mime_type) for PNG data."""
    return data, "image/png"


def _make_mock_engine(seed: int = 42):
    """Create a mock MitoAiEngine that returns deterministic results."""
    import numpy as np

    mock_engine = unittest.mock.MagicMock()
    rng = np.random.RandomState(seed)
    embedding = rng.randn(512).astype(np.float32)
    embedding /= np.linalg.norm(embedding)

    mock_engine.process.return_value = MitoAiResult(
        face_detected=True,
        bbox=(0.0, 0.0, 100.0, 100.0),
        landmarks=(
            (10.0, 10.0),
            (20.0, 10.0),
            (15.0, 15.0),
            (12.0, 20.0),
            (18.0, 20.0),
        ),
        quality=QualityResult(
            blur=120.5,
            brightness=128.0,
            contrast=45.2,
            face_width=100.0,
            face_height=100.0,
            yaw=0.05,
            roll=0.0,
        ),
        liveness=LivenessResult(
            label="real",
            live_prob=0.95,
            probs={"print": 0.02, "real": 0.95, "replay": 0.03},
            logits=(-1.0, 3.0, 0.5),
            model_version="mito-face-v1",
        ),
        embedding_dimension=512,
        model_version="mito-face-v1",
        processing_time_ms=42.5,
        embedding=embedding,
    )
    return mock_engine


def _mock_engine(monkeypatch, seed: int = 42):
    """Patch _get_engine to return a deterministic mock."""
    mock_engine = _make_mock_engine(seed)
    monkeypatch.setattr("app.api.face._get_engine", lambda settings: mock_engine)
    return mock_engine


class TestHealthEndpoint:
    """/health remains unauthenticated."""

    def test_health_returns_ok(self):
        resp = client.get("/health")
        assert resp.status_code == 200
        body = resp.json()
        assert body["status"] == "ok"
        assert body["service"] == "attendance-ai"


class TestApiKeyAuth:
    """API key is required for protected endpoints."""

    def test_enroll_without_api_key_returns_401(self):
        img_bytes, mime = _png_bytes(_make_test_image())
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"idempotency_key": "test-key-001"},
        )
        assert resp.status_code == 401

    def test_enroll_with_invalid_api_key_returns_401(self):
        img_bytes, mime = _png_bytes(_make_test_image())
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"idempotency_key": "test-key-001"},
            headers={"X-API-Key": "wrong-key"},
        )
        assert resp.status_code == 401

    def test_enroll_with_valid_api_key_succeeds(self, monkeypatch):
        _mock_engine(monkeypatch)
        img_bytes, mime = _png_bytes(_make_test_image())
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"idempotency_key": "test-key-002"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 200
        body = resp.json()
        assert body["enrolled"] is True
        assert body["model_version"] == "mito-face-v1"
        assert "embedding_reference" in body
        assert body["face_detected"] is True


class TestEnrollFace:
    """Enrollment endpoint behavior."""

    def test_valid_image_enrolls_successfully(self, monkeypatch):
        _mock_engine(monkeypatch)
        img_bytes, mime = _png_bytes(_make_test_image())
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"idempotency_key": "test-key-003"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 200
        body = resp.json()
        assert body["enrolled"] is True
        assert isinstance(body["embedding_reference"], str)
        assert len(body["embedding_reference"]) > 0
        assert "quality" in body
        assert "liveness" in body
        assert "processing_time_ms" in body

    def test_missing_image_returns_422(self, monkeypatch):
        _mock_engine(monkeypatch)
        resp = client.post(
            "/face/enroll",
            data={"idempotency_key": "test-key-004"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 422

    def test_invalid_mime_type_rejected(self, monkeypatch):
        _mock_engine(monkeypatch)
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.txt", b"not an image", "text/plain")},
            data={"idempotency_key": "test-key-005"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 422

    def test_non_image_data_rejected(self, monkeypatch):
        _mock_engine(monkeypatch)
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.png", b"fake image data", "image/png")},
            data={"idempotency_key": "test-key-006"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 422

    def test_embedding_not_exposed_in_enroll_response(self, monkeypatch):
        """Raw embedding vectors must never be returned to the caller."""
        _mock_engine(monkeypatch)
        img_bytes, mime = _png_bytes(_make_test_image())
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"idempotency_key": "test-key-007"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        body = resp.json()
        assert "embedding" not in body
        assert "embedding_vector" not in body
        assert "raw_embedding" not in body

    def test_employee_id_not_in_response(self, monkeypatch):
        """Employee identity must never appear in enrollment responses."""
        _mock_engine(monkeypatch)
        img_bytes, mime = _png_bytes(_make_test_image())
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"idempotency_key": "test-key-008"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        body = resp.json()
        assert "employee_id" not in body
        assert "employee_code" not in body
        assert "employee_name" not in body
        assert "user_id" not in body

    def test_idempotency_key_required(self, monkeypatch):
        _mock_engine(monkeypatch)
        img_bytes, mime = _png_bytes(_make_test_image())
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 422

    def test_empty_idempotency_key_rejected(self, monkeypatch):
        _mock_engine(monkeypatch)
        img_bytes, mime = _png_bytes(_make_test_image())
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"idempotency_key": "   "},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 400


class TestEnrollIdempotency:
    """Idempotency behavior for enrollment."""

    def test_same_key_same_image_returns_same_reference(self, monkeypatch):
        mock_engine = _mock_engine(monkeypatch)
        img_bytes, mime = _png_bytes(_make_test_image(seed=42))
        key = "idem-test-001"

        resp1 = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"idempotency_key": key},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp1.status_code == 200
        ref1 = resp1.json()["embedding_reference"]

        resp2 = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"idempotency_key": key},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp2.status_code == 200
        ref2 = resp2.json()["embedding_reference"]

        assert ref1 == ref2
        assert mock_engine.process.call_count == 1

    def test_same_key_different_image_returns_409(self, monkeypatch):
        _mock_engine(monkeypatch)
        img_a, mime_a = _png_bytes(_make_test_image(seed=42))
        img_b, mime_b = _png_bytes(_make_test_image(seed=99))
        key = "idem-test-002"

        resp1 = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_a, mime_a)},
            data={"idempotency_key": key},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp1.status_code == 200

        resp2 = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_b, mime_b)},
            data={"idempotency_key": key},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp2.status_code == 409

    def test_idempotent_retry_returns_existing_facts(self, monkeypatch):
        _mock_engine(monkeypatch)
        img_bytes, mime = _png_bytes(_make_test_image(seed=42))
        key = "idem-test-003"

        resp1 = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"idempotency_key": key},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp1.status_code == 200
        body1 = resp1.json()

        resp2 = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"idempotency_key": key},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp2.status_code == 200
        body2 = resp2.json()

        assert body1["embedding_reference"] == body2["embedding_reference"]
        assert body1["quality"]["blur"] == body2["quality"]["blur"]
        assert body1["liveness"]["label"] == body2["liveness"]["label"]


class TestEnrollFailure:
    """AI pipeline failures are handled safely."""

    def test_no_face_returns_422(self, monkeypatch):
        mock_engine = _mock_engine(monkeypatch)
        mock_engine.process.side_effect = NoFaceDetectedError("zero faces")
        img_bytes, mime = _png_bytes(_make_test_image())
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"idempotency_key": "fail-test-001"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 422
        body = resp.json()
        assert "employee_id" not in str(body)

    def test_multiple_faces_returns_422(self, monkeypatch):
        mock_engine = _mock_engine(monkeypatch)
        mock_engine.process.side_effect = MultipleFacesDetectedError("2 faces")
        img_bytes, mime = _png_bytes(_make_test_image())
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"idempotency_key": "fail-test-002"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 422
        body = resp.json()
        assert "employee_id" not in str(body)

    def test_model_error_returns_503(self, monkeypatch):
        mock_engine = _mock_engine(monkeypatch)
        mock_engine.process.side_effect = MitoAiError("model missing")
        img_bytes, mime = _png_bytes(_make_test_image())
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"idempotency_key": "fail-test-003"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 503

    def test_storage_failure_returns_503(self, monkeypatch):
        from app.api import face as face_module

        _mock_engine(monkeypatch)

        failing_storage = unittest.mock.MagicMock()
        failing_storage.store.side_effect = RuntimeError("database down")
        failing_storage.get_by_idempotency_key.return_value = None

        monkeypatch.setattr(face_module, "_get_storage", lambda: failing_storage)

        img_bytes, mime = _png_bytes(_make_test_image())
        resp = client.post(
            "/face/enroll",
            files={"image": ("test.png", img_bytes, mime)},
            data={"idempotency_key": "fail-test-004"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 503


class TestVerifyFace:
    """Verification endpoint behavior."""

    @pytest.fixture(autouse=True)
    def _enroll_before_verify(self, monkeypatch):
        """Enroll a face first so verify has an embedding to compare."""
        enrollment_img_bytes = _make_test_image(seed=42)
        img_bytes, mime = _png_bytes(enrollment_img_bytes)

        def _verify(img, stored_embedding):
            buf = io.BytesIO()
            img.save(buf, format="PNG")
            probe_bytes = buf.getvalue()

            if probe_bytes == enrollment_img_bytes:
                confidence = 0.95
            else:
                confidence = 0.0

            return FaceResult(
                face_detected=True,
                embedding=[0.0] * 64,
                confidence=confidence,
                liveness=False,
                liveness_reason="Liveness check is disabled.",
                processing_time_ms=10,
                model_version="mito-face-v1",
                quality_score=0.5,
            )

        mock_processor = unittest.mock.MagicMock()
        mock_processor.verify.side_effect = _verify
        monkeypatch.setattr("app.api.face._get_processor", lambda settings: mock_processor)

        _mock_engine(monkeypatch, seed=42)
        enroll_resp = client.post(
            "/face/enroll",
            files={"image": ("enroll.png", img_bytes, mime)},
            data={"idempotency_key": "verify-enroll-key"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert enroll_resp.status_code == 200
        self._embedding_reference = enroll_resp.json()["embedding_reference"]
        yield

    def _verify_data(self, img_bytes, mime):
        return {
            "files": {"image": ("verify.png", img_bytes, mime)},
            "data": {
                "employee_id": "EMP_VERIFY",
                "embedding_reference": self._embedding_reference,
            },
            "headers": {"X-API-Key": _TEST_API_KEY},
        }

    def test_verify_returns_correct_schema(self):
        img_bytes, mime = _png_bytes(_make_test_image(seed=42))
        kwargs = self._verify_data(img_bytes, mime)
        resp = client.post("/face/verify", **kwargs)
        assert resp.status_code == 200
        body = resp.json()
        assert "verified" in body
        assert "confidence" in body
        assert "liveness" in body
        assert "model_version" in body
        assert "face_detected" in body
        assert "processing_time_ms" in body
        assert "quality_score" in body
        assert isinstance(body["verified"], bool)
        assert isinstance(body["confidence"], (int, float))
        assert isinstance(body["liveness"], bool)
        assert isinstance(body["processing_time_ms"], int)

    def test_verify_same_image_returns_high_confidence(self):
        img_bytes, mime = _png_bytes(_make_test_image(seed=42))
        kwargs = self._verify_data(img_bytes, mime)
        resp = client.post("/face/verify", **kwargs)
        assert resp.status_code == 200
        body = resp.json()
        assert body["confidence"] >= 0.9

    def test_verify_face_mismatch_returns_verified_false(self):
        different_img, mime = _png_bytes(_make_test_image(seed=99))
        kwargs = self._verify_data(different_img, mime)
        resp = client.post("/face/verify", **kwargs)
        assert resp.status_code == 200
        body = resp.json()
        assert body["verified"] is False

    def test_verify_liveness_field_is_present(self):
        img_bytes, mime = _png_bytes(_make_test_image(seed=42))
        kwargs = self._verify_data(img_bytes, mime)
        resp = client.post("/face/verify", **kwargs)
        body = resp.json()
        assert "liveness" in body
        assert "liveness_reason" in body

    def test_verify_liveness_is_false_in_dev_mode(self):
        """In disabled liveness mode, liveness must be False (safe default)."""
        img_bytes, mime = _png_bytes(_make_test_image(seed=42))
        kwargs = self._verify_data(img_bytes, mime)
        resp = client.post("/face/verify", **kwargs)
        body = resp.json()
        assert body["liveness"] is False

    def test_verify_unknown_employee_returns_verified_false(self):
        img_bytes, mime = _png_bytes(_make_test_image(seed=7))
        kwargs = self._verify_data(img_bytes, mime)
        kwargs["data"]["embedding_reference"] = "nonexistent-ref"
        resp = client.post("/face/verify", **kwargs)
        assert resp.status_code == 200
        body = resp.json()
        assert body["verified"] is False
        assert body["confidence"] == 0.0

    def test_verify_invalid_image_rejected(self):
        resp = client.post(
            "/face/verify",
            files={"image": ("bad.png", b"not an image", "image/png")},
            data={"employee_id": "EMP_VERIFY"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 400

    def test_verify_response_does_not_expose_embedding(self):
        """Raw embeddings must never be returned in verification responses."""
        img_bytes, mime = _png_bytes(_make_test_image(seed=42))
        kwargs = self._verify_data(img_bytes, mime)
        resp = client.post("/face/verify", **kwargs)
        body = resp.json()
        assert "embedding" not in body
        assert "embedding_vector" not in body
        assert "raw_embedding" not in body


class TestMalformedRequest:
    """Malformed multipart requests return clear errors."""

    def test_verify_missing_image_returns_422(self):
        resp = client.post(
            "/face/verify",
            data={"employee_id": "EMP001"},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 422

    def test_verify_missing_employee_id_returns_422(self):
        img_bytes, mime = _png_bytes(_make_test_image())
        resp = client.post(
            "/face/verify",
            files={"image": ("test.png", img_bytes, mime)},
            headers={"X-API-Key": _TEST_API_KEY},
        )
        assert resp.status_code == 422


class TestExceptionHandling:
    """Unhandled exceptions never leak stack traces."""

    def test_500_response_does_not_leak_traceback(self, monkeypatch):
        from app.api import face as face_module
        from app.core.face import FaceProcessor

        original = face_module._get_processor

        def _boom(settings):
            raise RuntimeError("internal secret leak")

        monkeypatch.setattr(face_module, "_get_processor", _boom)
        try:
            img_bytes, mime = _png_bytes(_make_test_image())
            resp = client.post(
                "/face/verify",
                files={"image": ("test.png", img_bytes, mime)},
                data={
                    "employee_id": "EMP_VERIFY",
                    "embedding_reference": "nonexistent-ref",
                },
                headers={"X-API-Key": _TEST_API_KEY},
            )
            assert resp.status_code == 500
            body = resp.json()
            assert "internal secret leak" not in str(body)
            assert body.get("detail") == "Face verification processing failed."
        finally:
            monkeypatch.setattr(face_module, "_get_processor", original)
