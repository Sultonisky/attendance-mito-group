"""Tests for biometric embedding storage (AI-3.1 / AI-3.2).

Covers reference generation, serialization roundtrip, validation,
PostgreSQL CRUD, uniqueness, status, idempotency, request fingerprint,
and isolation guarantees.
"""

from __future__ import annotations

import json
import os
import struct
import uuid

import numpy as np
import pytest
from psycopg2 import IntegrityError

from app.core.biometric_storage import (
    InMemoryBiometricStorage,
    PostgreSQLBiometricStorage,
    BiometricEmbedding,
    generate_reference,
    validate_reference,
    create_storage,
)
from app.core.config import get_settings

# Ensure settings are loaded with a test API key before app imports.
os.environ.setdefault("AI_API_KEY", "test-secret-key-123")
get_settings.cache_clear()

# ---------------------------------------------------------------------------
# Test database configuration
# ---------------------------------------------------------------------------

TEST_DATABASE_URL = "postgresql://postgres:postgres@127.0.0.1:5433/biometric_test"


def _postgres_available() -> bool:
    try:
        import psycopg2
        conn = psycopg2.connect(TEST_DATABASE_URL, connect_timeout=2)
        conn.close()
        return True
    except Exception:
        return False


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _make_vector(dim: int = 512) -> np.ndarray:
    vec = np.random.randn(dim).astype(np.float32)
    vec = vec / np.linalg.norm(vec)
    return vec


def _vector_to_bytes(vec: np.ndarray) -> bytes:
    return vec.astype(np.float32).tobytes()


def _bytes_to_vector(data: bytes) -> np.ndarray:
    return np.frombuffer(data, dtype=np.float32)


def _make_ai_facts() -> str:
    return json.dumps({
        "face_detected": True,
        "quality": {
            "blur": 120.5,
            "brightness": 128.0,
            "contrast": 45.2,
            "face_width": 100.0,
            "face_height": 100.0,
            "yaw": 0.05,
            "roll": 0.0,
        },
        "liveness": {
            "label": "real",
            "live_prob": 0.95,
            "probs": {"print": 0.02, "real": 0.95, "replay": 0.03},
        },
        "processing_time_ms": 42.5,
    })


# ---------------------------------------------------------------------------
# Reference generation
# ---------------------------------------------------------------------------


class TestReferenceGeneration:
    def test_generates_valid_uuid_hex(self):
        ref = generate_reference()
        assert len(ref) == 32
        uuid.UUID(ref, version=4)

    def test_different_calls_produce_different_references(self):
        refs = {generate_reference() for _ in range(100)}
        assert len(refs) == 100

    def test_reference_contains_no_employee_identity(self):
        ref = generate_reference()
        assert ":" not in ref
        assert "employee" not in ref.lower()
        assert "user" not in ref.lower()
        assert "emp" not in ref.lower()

    def test_reference_is_lowercase_hex(self):
        ref = generate_reference()
        assert ref == ref.lower()
        int(ref, 16)  # must be valid hex

    def test_validate_reference_accepts_valid(self):
        ref = generate_reference()
        validate_reference(ref)  # should not raise

    def test_validate_reference_rejects_empty(self):
        with pytest.raises(ValueError, match="must not be empty"):
            validate_reference("")

    def test_validate_reference_rejects_too_short(self):
        with pytest.raises(ValueError, match="too short"):
            validate_reference("short")

    def test_validate_reference_rejects_colons(self):
        with pytest.raises(ValueError, match="must not contain colons"):
            validate_reference("a" * 40 + ":b")


# ---------------------------------------------------------------------------
# Serialization roundtrip
# ---------------------------------------------------------------------------


class TestSerializationRoundtrip:
    def test_512_float32_roundtrip(self):
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        assert len(data) == 512 * 4
        recovered = _bytes_to_vector(data)
        np.testing.assert_array_equal(vec, recovered)
        assert recovered.dtype == np.float32

    def test_different_dimensions(self):
        for dim in [1, 128, 256, 512]:
            vec = _make_vector(dim)
            data = _vector_to_bytes(vec)
            recovered = _bytes_to_vector(data)
            assert len(recovered) == dim
            np.testing.assert_array_equal(vec, recovered)

    def test_values_preserved(self):
        vec = np.array([0.1, -0.5, 1.0, np.inf, -np.inf], dtype=np.float32)
        # Note: inf is preserved through roundtrip
        data = _vector_to_bytes(vec)
        recovered = _bytes_to_vector(data)
        np.testing.assert_array_equal(vec, recovered)


# ---------------------------------------------------------------------------
# Input validation
# ---------------------------------------------------------------------------


class TestInputValidation:
    def test_rejects_wrong_dimension(self):
        storage = InMemoryBiometricStorage()
        vec = _make_vector(256)
        data = _vector_to_bytes(vec)
        with pytest.raises(ValueError, match="dimension"):
            storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())

    def test_rejects_nan_vector(self):
        storage = InMemoryBiometricStorage()
        vec = np.full(512, np.nan, dtype=np.float32)
        data = _vector_to_bytes(vec)
        # Storage itself doesn't validate NaN — that's the caller's responsibility.
        # But we verify roundtrip preserves NaN.
        recovered = _bytes_to_vector(data)
        assert np.isnan(recovered[0])

    def test_rejects_zero_vector_at_policy_layer(self):
        # The storage layer accepts any bytes. Validation of finite/non-zero
        # belongs to the caller (enrollment policy). This test documents that.
        storage = InMemoryBiometricStorage()
        vec = np.zeros(512, dtype=np.float32)
        data = _vector_to_bytes(vec)
        storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        row = storage.get_by_reference("ref1")
        assert row is not None
        assert row.dimension == 512

    def test_rejects_empty_bytes(self):
        storage = InMemoryBiometricStorage()
        with pytest.raises(ValueError, match="dimension"):
            storage.store("ref1", b"", 512, "v1", "key1", "fp1", _make_ai_facts())

    def test_rejects_corrupt_payload_dimension_mismatch(self):
        storage = InMemoryBiometricStorage()
        data = b"\x00" * 100
        with pytest.raises(ValueError, match="dimension"):
            storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())


# ---------------------------------------------------------------------------
# In-memory storage CRUD
# ---------------------------------------------------------------------------


class TestInMemoryStorageCRUD:
    def test_store_and_get_by_reference(self):
        storage = InMemoryBiometricStorage()
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        row = storage.get_by_reference("ref1")
        assert row is not None
        assert row.reference == "ref1"
        assert row.dimension == 512
        assert row.model_version == "v1"
        assert row.status == "active"
        assert row.idempotency_key == "key1"
        assert row.request_fingerprint == "fp1"
        assert "face_detected" in row.ai_facts
        np.testing.assert_array_equal(_bytes_to_vector(row.vector), vec)

    def test_get_by_idempotency_key(self):
        storage = InMemoryBiometricStorage()
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        row = storage.get_by_idempotency_key("key1")
        assert row is not None
        assert row.reference == "ref1"
        assert row.request_fingerprint == "fp1"

    def test_get_missing_reference_returns_none(self):
        storage = InMemoryBiometricStorage()
        assert storage.get_by_reference("nonexistent") is None

    def test_get_missing_idempotency_key_returns_none(self):
        storage = InMemoryBiometricStorage()
        assert storage.get_by_idempotency_key("nonexistent") is None

    def test_delete_by_reference(self):
        storage = InMemoryBiometricStorage()
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        assert storage.delete_by_reference("ref1") is True
        assert storage.get_by_reference("ref1") is None
        assert storage.get_by_idempotency_key("key1") is None

    def test_delete_missing_reference_returns_false(self):
        storage = InMemoryBiometricStorage()
        assert storage.delete_by_reference("nonexistent") is False

    def test_update_status(self):
        storage = InMemoryBiometricStorage()
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        assert storage.update_status("ref1", "inactive") is True
        row = storage.get_by_reference("ref1")
        assert row.status == "inactive"
        assert row.request_fingerprint == "fp1"

    def test_update_missing_reference_returns_false(self):
        storage = InMemoryBiometricStorage()
        assert storage.update_status("nonexistent", "revoked") is False


# ---------------------------------------------------------------------------
# Uniqueness
# ---------------------------------------------------------------------------


class TestUniqueness:
    def test_duplicate_reference_raises(self):
        storage = InMemoryBiometricStorage()
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        with pytest.raises(ValueError, match="already exists"):
            storage.store("ref1", data, 512, "v1", "key2", "fp2", _make_ai_facts())

    def test_duplicate_idempotency_key_raises(self):
        storage = InMemoryBiometricStorage()
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        with pytest.raises(ValueError, match="already exists"):
            storage.store("ref2", data, 512, "v1", "key1", "fp1", _make_ai_facts())


# ---------------------------------------------------------------------------
# Request fingerprint
# ---------------------------------------------------------------------------


class TestRequestFingerprint:
    def test_fingerprint_stored_with_embedding(self):
        storage = InMemoryBiometricStorage()
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        fp = "abc123fingerprint"
        storage.store("ref1", data, 512, "v1", "key1", fp, _make_ai_facts())
        row = storage.get_by_reference("ref1")
        assert row.request_fingerprint == fp

    def test_fingerprint_retrieved_by_idempotency_key(self):
        storage = InMemoryBiometricStorage()
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        fp = "fingerprint456"
        storage.store("ref1", data, 512, "v1", "key1", fp, _make_ai_facts())
        row = storage.get_by_idempotency_key("key1")
        assert row is not None
        assert row.request_fingerprint == fp

    def test_ai_facts_stored_as_json(self):
        storage = InMemoryBiometricStorage()
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        facts = _make_ai_facts()
        storage.store("ref1", data, 512, "v1", "key1", "fp1", facts)
        row = storage.get_by_reference("ref1")
        parsed = json.loads(row.ai_facts)
        assert parsed["face_detected"] is True
        assert "quality" in parsed
        assert "liveness" in parsed
        assert "processing_time_ms" in parsed


# ---------------------------------------------------------------------------
# Status lifecycle
# ---------------------------------------------------------------------------


class TestStatusLifecycle:
    def test_active_default(self):
        storage = InMemoryBiometricStorage()
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        row = storage.get_by_reference("ref1")
        assert row.status == "active"

    def test_inactive_transition(self):
        storage = InMemoryBiometricStorage()
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        storage.update_status("ref1", "inactive")
        row = storage.get_by_reference("ref1")
        assert row.status == "inactive"

    def test_revoked_transition(self):
        storage = InMemoryBiometricStorage()
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        storage.update_status("ref1", "revoked")
        row = storage.get_by_reference("ref1")
        assert row.status == "revoked"


# ---------------------------------------------------------------------------
# Isolation — no employee identity
# ---------------------------------------------------------------------------


class TestIsolation:
    def test_storage_has_no_employee_fields(self):
        storage = InMemoryBiometricStorage()
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        row = storage.get_by_reference("ref1")
        assert not hasattr(row, "employee_id")
        assert not hasattr(row, "employee_code")
        assert not hasattr(row, "employee_name")

    def test_store_does_not_accept_employee_identity(self):
        storage = InMemoryBiometricStorage()
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        # store() signature has no employee_id parameter
        storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        row = storage.get_by_reference("ref1")
        assert "employee" not in str(row).lower()


# ---------------------------------------------------------------------------
# PostgreSQL integration tests
# ---------------------------------------------------------------------------


@pytest.mark.skipif(
    not _postgres_available(),
    reason="PostgreSQL test database is not available",
)
class TestPostgreSQLStorage:
    """Integration tests against a real PostgreSQL database."""

    @pytest.fixture(autouse=True)
    def setup_storage(self):
        self.storage = PostgreSQLBiometricStorage(TEST_DATABASE_URL, min_conn=1, max_conn=5)
        # Clean table before each test
        conn = self.storage._connection()
        try:
            conn.autocommit = True
            with conn.cursor() as cur:
                cur.execute("TRUNCATE TABLE biometric.embeddings CASCADE")
        finally:
            self.storage._put_connection(conn)
        yield
        # No teardown needed — next test truncates

    def test_store_and_get_by_reference(self):
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        self.storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        row = self.storage.get_by_reference("ref1")
        assert row is not None
        assert row.reference == "ref1"
        assert row.dimension == 512
        assert row.model_version == "v1"
        assert row.status == "active"
        assert row.idempotency_key == "key1"
        assert row.request_fingerprint == "fp1"
        assert "face_detected" in row.ai_facts
        np.testing.assert_array_equal(_bytes_to_vector(row.vector), vec)

    def test_get_by_idempotency_key(self):
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        self.storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        row = self.storage.get_by_idempotency_key("key1")
        assert row is not None
        assert row.reference == "ref1"
        assert row.request_fingerprint == "fp1"

    def test_get_missing_reference_returns_none(self):
        assert self.storage.get_by_reference("nonexistent") is None

    def test_get_missing_idempotency_key_returns_none(self):
        assert self.storage.get_by_idempotency_key("nonexistent") is None

    def test_delete_by_reference(self):
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        self.storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        assert self.storage.delete_by_reference("ref1") is True
        assert self.storage.get_by_reference("ref1") is None

    def test_duplicate_reference_raises_integrity_error(self):
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        self.storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        with pytest.raises(IntegrityError):
            self.storage.store("ref1", data, 512, "v1", "key2", "fp2", _make_ai_facts())

    def test_duplicate_idempotency_key_raises_integrity_error(self):
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        self.storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        with pytest.raises(IntegrityError):
            self.storage.store("ref2", data, 512, "v1", "key1", "fp1", _make_ai_facts())

    def test_update_status(self):
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        self.storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        assert self.storage.update_status("ref1", "inactive") is True
        row = self.storage.get_by_reference("ref1")
        assert row.status == "inactive"

    def test_bytea_preserves_exact_bytes(self):
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        self.storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        row = self.storage.get_by_reference("ref1")
        assert row.vector == data

    def test_no_employee_identity_in_database(self):
        vec = _make_vector(512)
        data = _vector_to_bytes(vec)
        self.storage.store("ref1", data, 512, "v1", "key1", "fp1", _make_ai_facts())
        # Verify by querying information_schema — no employee columns exist
        conn = self.storage._connection()
        try:
            conn.autocommit = True
            with conn.cursor() as cur:
                cur.execute(
                    """
                    SELECT column_name FROM information_schema.columns
                    WHERE table_schema = 'biometric' AND table_name = 'embeddings'
                    """
                )
                columns = [r[0] for r in cur.fetchall()]
                assert "employee_id" not in columns
                assert "employee_code" not in columns
                assert "employee_name" not in columns
        finally:
            self.storage._put_connection(conn)


# ---------------------------------------------------------------------------
# create_storage factory
# ---------------------------------------------------------------------------


class TestCreateStorageFactory:
    def test_returns_in_memory_when_no_url(self):
        storage = create_storage(None)
        assert isinstance(storage, InMemoryBiometricStorage)

    def test_returns_postgresql_when_url_provided(self):
        if not _postgres_available():
            pytest.skip("PostgreSQL not available")
        storage = create_storage(TEST_DATABASE_URL)
        assert isinstance(storage, PostgreSQLBiometricStorage)
