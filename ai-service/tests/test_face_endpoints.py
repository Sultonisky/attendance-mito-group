"""Tests for FastAPI face enrollment idempotency and concurrency (AI-3.6).

Covers:
- Same idempotency key + same image returns deterministic existing result.
- Same idempotency key + different image returns 409.
- Concurrent requests with the same key do not produce 503 on the duplicate.
- Actual storage failures remain 503.
"""

from __future__ import annotations

import io
import os
import threading
import unittest.mock

import pytest
from fastapi import HTTPException
from fastapi.testclient import TestClient
from PIL import Image

from app.ai.engine.mito_ai_engine import MitoAiResult, QualityResult, LivenessResult
from app.api.face import router as face_router
from app.core.biometric_storage import PostgreSQLBiometricStorage, create_storage, generate_reference
from app.core.config import get_settings
from app.main import app

os.environ.setdefault("AI_API_KEY", "test-secret-key-123")
get_settings.cache_clear()

TEST_DATABASE_URL = "postgresql://postgres:postgres@127.0.0.1:5433/biometric_test"


def _postgres_available() -> bool:
    try:
        import psycopg2
        conn = psycopg2.connect(TEST_DATABASE_URL, connect_timeout=2)
        conn.close()
        return True
    except Exception:
        return False


def _make_test_image(size=(64, 64), seed=0) -> bytes:
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
    return data, "image/png"


def _make_mock_engine(seed: int = 42):
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
    mock_engine = _make_mock_engine(seed)
    monkeypatch.setattr("app.api.face._get_engine", lambda settings: mock_engine)
    return mock_engine


def _auth_headers() -> dict:
    return {"X-API-Key": "test-secret-key-123"}


def _post_form(image_bytes: bytes, mime: str, idempotency_key: str) -> dict:
    return {
        "files": {
            "image": ("face.png", image_bytes, mime),
        },
        "data": {
            "idempotency_key": idempotency_key,
        },
        "headers": _auth_headers(),
    }


@pytest.fixture()
def client() -> TestClient:
    return TestClient(app)


@pytest.fixture()
def storage():
    storage = create_storage(TEST_DATABASE_URL)
    conn = storage._connection()
    try:
        conn.autocommit = True
        with conn.cursor() as cur:
            cur.execute("TRUNCATE TABLE biometric.embeddings CASCADE")
    finally:
        storage._put_connection(conn)
    yield storage


class TestEnrollmentIdempotency:
    def test_same_key_same_image_returns_same_reference(self, client: TestClient, storage: PostgreSQLBiometricStorage, monkeypatch: pytest.MonkeyPatch):
        _mock_engine(monkeypatch)
        image_bytes, mime = _png_bytes(_make_test_image(seed=1))
        key = "same-key-same-image"

        response_a = client.post("/face/enroll", **_post_form(image_bytes, mime, key))
        assert response_a.status_code == 200, response_a.text
        data_a = response_a.json()

        response_b = client.post("/face/enroll", **_post_form(image_bytes, mime, key))
        assert response_b.status_code == 200, response_b.text
        data_b = response_b.json()

        assert data_a["embedding_reference"] == data_b["embedding_reference"]
        assert data_a["model_version"] == data_b["model_version"]
        assert data_a["face_detected"] == data_b["face_detected"]

    def test_same_key_different_image_returns_409(self, client: TestClient, monkeypatch: pytest.MonkeyPatch):
        _mock_engine(monkeypatch, seed=1)
        image_a, mime_a = _png_bytes(_make_test_image(seed=1))
        response_a = client.post("/face/enroll", **_post_form(image_a, mime_a, "same-key-diff-image"))
        assert response_a.status_code == 200

        _mock_engine(monkeypatch, seed=99)
        image_b, mime_b = _png_bytes(_make_test_image(seed=99))
        response_b = client.post("/face/enroll", **_post_form(image_b, mime_b, "same-key-diff-image"))
        assert response_b.status_code == 409

    def test_concurrent_same_key_same_image_both_succeed(self, client: TestClient, monkeypatch: pytest.MonkeyPatch):
        _mock_engine(monkeypatch)
        image_bytes, mime = _png_bytes(_make_test_image(seed=1))
        key = "concurrent-same-key"
        results = []
        exceptions = []

        def make_request():
            try:
                response = client.post("/face/enroll", **_post_form(image_bytes, mime, key))
                results.append(response)
            except Exception as exc:
                exceptions.append(exc)

        threads = [threading.Thread(target=make_request) for _ in range(2)]
        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join()

        assert not exceptions, f"Unexpected exceptions: {exceptions}"
        assert len(results) == 2
        assert all(r.status_code == 200 for r in results), f"Unexpected status codes: {[r.status_code for r in results]}"
        references = {r.json()["embedding_reference"] for r in results}
        assert len(references) == 1, "Concurrent same-key requests must return the same embedding_reference."

    def test_concurrent_duplicate_insertion_only_one_succeeds(self, client: TestClient, monkeypatch: pytest.MonkeyPatch):
        _mock_engine(monkeypatch, seed=1)
        image_a, mime_a = _png_bytes(_make_test_image(seed=1))
        _mock_engine(monkeypatch, seed=2)
        image_b, mime_b = _png_bytes(_make_test_image(seed=2))
        key_a = "concurrent-dup-a"
        key_b = "concurrent-dup-b"

        results = []
        exceptions = []

        def make_request_a():
            try:
                results.append(("A", client.post("/face/enroll", **_post_form(image_a, mime_a, key_a))))
            except Exception as exc:
                exceptions.append(("A", exc))

        def make_request_b():
            try:
                results.append(("B", client.post("/face/enroll", **_post_form(image_b, mime_b, key_b))))
            except Exception as exc:
                exceptions.append(("B", exc))

        thread_a = threading.Thread(target=make_request_a)
        thread_b = threading.Thread(target=make_request_b)
        thread_a.start()
        thread_b.start()
        thread_a.join()
        thread_b.join()

        assert not exceptions, f"Unexpected exceptions: {exceptions}"
        assert len(results) == 2
        assert all(r[1].status_code == 200 for r in results), f"Unexpected status codes: {[(r[0], r[1].status_code) for r in results]}"

        references = [r[1].json()["embedding_reference"] for r in results]
        assert len(references) == 2
        assert len(set(references)) == 2, "Different idempotency keys must produce distinct embedding_references."

    def test_storage_failure_returns_503(self, client: TestClient, monkeypatch: pytest.MonkeyPatch):
        _mock_engine(monkeypatch)

        class FailingStorage:
            def get_by_idempotency_key(self, key):
                return None

            def store(self, **kwargs):
                raise Exception("Simulated storage failure")

        monkeypatch.setattr("app.api.face._storage", None, raising=False)
        monkeypatch.setattr("app.api.face._get_storage", lambda: FailingStorage())

        image_bytes, mime = _png_bytes(_make_test_image())
        response = client.post("/face/enroll", **_post_form(image_bytes, mime, "storage-failure-key"))
        assert response.status_code == 503


@pytest.mark.skipif(
    not _postgres_available(),
    reason="PostgreSQL test database is not available",
)
class TestPostgreSQLEndpointIdempotency:
    @pytest.fixture(autouse=True)
    def setup_storage(self):
        self.storage = PostgreSQLBiometricStorage(TEST_DATABASE_URL, min_conn=1, max_conn=5)
        conn = self.storage._connection()
        try:
            conn.autocommit = True
            with conn.cursor() as cur:
                cur.execute("TRUNCATE TABLE biometric.embeddings CASCADE")
        finally:
            self.storage._put_connection(conn)
        yield

    def test_integrity_error_returns_existing_response(self, monkeypatch: pytest.MonkeyPatch):
        _mock_engine(monkeypatch)

        settings = get_settings()
        settings.api_key = "test-secret-key-123"

        reference = generate_reference()
        import json
        import numpy as np
        vector = np.random.randn(64).astype(np.float32).tobytes()
        ai_facts = json.dumps({
            "face_detected": True,
            "quality": {"blur": 100.0, "brightness": 128.0, "contrast": 45.0, "face_width": 100.0, "face_height": 100.0, "yaw": 0.0, "roll": 0.0},
            "liveness": {"label": "real", "live_prob": 0.95, "probs": {"real": 0.95, "print": 0.02, "replay": 0.03}},
            "processing_time_ms": 42.0,
        })
        self.storage.store(
            reference=reference,
            vector=vector,
            dimension=64,
            model_version="mito-face-v1",
            idempotency_key="race-key",
            request_fingerprint="fp-race",
            ai_facts=ai_facts,
        )

        image_bytes = _make_test_image(seed=42)
        img_bytes, mime = _png_bytes(image_bytes)
        fingerprint = "fp-race"

        from app.core.image_utils import validate_and_decode_image
        from app.core.face import FaceProcessor

        img = validate_and_decode_image(
            image_bytes=img_bytes,
            mime_type=mime,
            max_size_bytes=settings.max_image_size_bytes,
            max_dimension=settings.max_image_dimension,
        )
        processor = FaceProcessor(settings)
        result = processor.enroll(img)
        result_vector = np.asarray(result[0], dtype="float32").tobytes()

        with pytest.raises(Exception):
            self.storage.store(
                reference=generate_reference(),
                vector=result_vector,
                dimension=64,
                model_version="mito-face-v1",
                idempotency_key="race-key",
                request_fingerprint=fingerprint,
                ai_facts=ai_facts,
            )

        existing = self.storage.get_by_idempotency_key("race-key")
        assert existing is not None
        assert existing.request_fingerprint == "fp-race"
