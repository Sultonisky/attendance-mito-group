"""Durable biometric embedding storage for FastAPI.

FastAPI owns this bounded context. Laravel never accesses it directly.

Storage backend: PostgreSQL ``biometric`` schema.
Raw embeddings are persisted as ``BYTEA`` and never returned to Laravel.
"""

from __future__ import annotations

import json
import logging
import threading
import uuid
from dataclasses import dataclass
from typing import Protocol

import numpy as np

from app.core.config import get_settings

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# DDL
# ---------------------------------------------------------------------------

_SCHEMA_DDL = """
CREATE SCHEMA IF NOT EXISTS biometric;

CREATE TABLE IF NOT EXISTS biometric.embeddings (
    reference VARCHAR(255) PRIMARY KEY,
    vector BYTEA NOT NULL,
    dimension INTEGER NOT NULL,
    model_version VARCHAR(100) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'active',
    idempotency_key VARCHAR(255) NOT NULL UNIQUE,
    request_fingerprint VARCHAR(255) NOT NULL,
    ai_facts JSONB NOT NULL,
    enrolled_at TIMESTAMP NOT NULL DEFAULT NOW(),
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
"""

# ---------------------------------------------------------------------------
# Domain model
# ---------------------------------------------------------------------------


@dataclass(frozen=True)
class BiometricEmbedding:
    """Raw embedding record as stored in PostgreSQL."""

    reference: str
    vector: bytes
    dimension: int
    model_version: str
    status: str
    idempotency_key: str
    request_fingerprint: str
    ai_facts: str
    enrolled_at: str
    created_at: str
    updated_at: str


# ---------------------------------------------------------------------------
# Abstraction
# ---------------------------------------------------------------------------


class BiometricStorage(Protocol):
    """Minimal interface for biometric embedding storage."""

    def store(
        self,
        reference: str,
        vector: bytes,
        dimension: int,
        model_version: str,
        idempotency_key: str,
        request_fingerprint: str,
        ai_facts: str,
    ) -> None:
        """Persist a new embedding."""
        ...

    def get_by_reference(self, reference: str) -> BiometricEmbedding | None:
        """Retrieve an embedding by its opaque reference."""
        ...

    def get_by_idempotency_key(self, idempotency_key: str) -> BiometricEmbedding | None:
        """Retrieve an embedding by idempotency key."""
        ...

    def delete_by_reference(self, reference: str) -> bool:
        """Delete an embedding by reference. Returns True if deleted."""
        ...

    def update_status(self, reference: str, status: str) -> bool:
        """Update embedding status. Returns True if updated."""
        ...


# ---------------------------------------------------------------------------
# PostgreSQL implementation
# ---------------------------------------------------------------------------


class PostgreSQLBiometricStorage:
    """PostgreSQL-backed biometric embedding storage.

    Uses ``psycopg2`` with a ``ThreadedConnectionPool``. FastAPI is the
    sole accessor. No employee identity is ever stored.
    """

    def __init__(self, database_url: str, min_conn: int = 1, max_conn: int = 10) -> None:
        self._database_url = database_url
        self._pool = self._create_pool(min_conn, max_conn)
        self._ensure_schema()

    # ------------------------------------------------------------------
    # Connection pool
    # ------------------------------------------------------------------

    def _create_pool(self, min_conn: int, max_conn: int):
        import psycopg2
        from psycopg2 import pool

        return pool.ThreadedConnectionPool(min_conn, max_conn, self._database_url)

    # ------------------------------------------------------------------
    # Schema
    # ------------------------------------------------------------------

    def _ensure_schema(self) -> None:
        conn = self._pool.getconn()
        try:
            conn.autocommit = True
            with conn.cursor() as cur:
                cur.execute(_SCHEMA_DDL)
        finally:
            self._pool.putconn(conn)

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------

    def _connection(self):
        return self._pool.getconn()

    def _put_connection(self, conn) -> None:
        self._pool.putconn(conn)

    @staticmethod
    def _row_to_embedding(row: tuple) -> BiometricEmbedding:
        (
            reference,
            vector,
            dimension,
            model_version,
            status,
            idempotency_key,
            request_fingerprint,
            ai_facts,
            enrolled_at,
            created_at,
            updated_at,
        ) = row
        ai_facts_str = ai_facts if isinstance(ai_facts, str) else json.dumps(ai_facts)
        return BiometricEmbedding(
            reference=reference,
            vector=vector.tobytes() if hasattr(vector, "tobytes") else bytes(vector),
            dimension=int(dimension),
            model_version=str(model_version),
            status=str(status),
            idempotency_key=str(idempotency_key),
            request_fingerprint=str(request_fingerprint),
            ai_facts=ai_facts_str,
            enrolled_at=enrolled_at.isoformat() if enrolled_at else "",
            created_at=created_at.isoformat() if created_at else "",
            updated_at=updated_at.isoformat() if updated_at else "",
        )

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------

    def store(
        self,
        reference: str,
        vector: bytes,
        dimension: int,
        model_version: str,
        idempotency_key: str,
        request_fingerprint: str,
        ai_facts: str,
    ) -> None:
        """Persist a new embedding.

        Raises ``IntegrityError`` if ``reference`` or ``idempotency_key``
        already exists.
        """
        import psycopg2

        self._validate_store_inputs(reference, vector, dimension, model_version, idempotency_key, request_fingerprint, ai_facts)

        conn = self._connection()
        try:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    INSERT INTO biometric.embeddings
                        (reference, vector, dimension, model_version, status, idempotency_key, request_fingerprint, ai_facts)
                    VALUES (%s, %s, %s, %s, 'active', %s, %s, %s)
                    """,
                    (reference, psycopg2.Binary(vector), dimension, model_version, idempotency_key, request_fingerprint, ai_facts),
                )
            conn.commit()
        except Exception:
            conn.rollback()
            raise
        finally:
            self._put_connection(conn)

    @staticmethod
    def _validate_store_inputs(
        reference: str,
        vector: bytes,
        dimension: int,
        model_version: str,
        idempotency_key: str,
        request_fingerprint: str,
        ai_facts: str,
    ) -> None:
        if not reference:
            raise ValueError("reference must not be empty.")
        if not model_version:
            raise ValueError("model_version must not be empty.")
        if not idempotency_key:
            raise ValueError("idempotency_key must not be empty.")
        if not request_fingerprint:
            raise ValueError("request_fingerprint must not be empty.")
        if not ai_facts:
            raise ValueError("ai_facts must not be empty.")
        if dimension <= 0:
            raise ValueError(f"dimension must be positive, got {dimension}.")
        expected_len = dimension * 4  # float32 = 4 bytes
        if len(vector) != expected_len:
            raise ValueError(
                f"vector bytes length {len(vector)} does not match "
                f"dimension {dimension} (expected {expected_len} bytes)."
            )

    def get_by_reference(self, reference: str) -> BiometricEmbedding | None:
        conn = self._connection()
        try:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    SELECT reference, vector, dimension, model_version, status,
                           idempotency_key, request_fingerprint, ai_facts,
                           enrolled_at, created_at, updated_at
                    FROM biometric.embeddings
                    WHERE reference = %s
                    """,
                    (reference,),
                )
                row = cur.fetchone()
                if row is None:
                    return None
                return self._row_to_embedding(row)
        finally:
            self._put_connection(conn)

    def get_by_idempotency_key(self, idempotency_key: str) -> BiometricEmbedding | None:
        conn = self._connection()
        try:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    SELECT reference, vector, dimension, model_version, status,
                           idempotency_key, request_fingerprint, ai_facts,
                           enrolled_at, created_at, updated_at
                    FROM biometric.embeddings
                    WHERE idempotency_key = %s
                    """,
                    (idempotency_key,),
                )
                row = cur.fetchone()
                if row is None:
                    return None
                return self._row_to_embedding(row)
        finally:
            self._put_connection(conn)

    def delete_by_reference(self, reference: str) -> bool:
        conn = self._connection()
        try:
            with conn.cursor() as cur:
                cur.execute(
                    "DELETE FROM biometric.embeddings WHERE reference = %s",
                    (reference,),
                )
                deleted = cur.rowcount > 0
            conn.commit()
            return deleted
        except Exception:
            conn.rollback()
            raise
        finally:
            self._put_connection(conn)

    def update_status(self, reference: str, status: str) -> bool:
        conn = self._connection()
        try:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    UPDATE biometric.embeddings
                    SET status = %s, updated_at = NOW()
                    WHERE reference = %s
                    """,
                    (status, reference),
                )
                updated = cur.rowcount > 0
            conn.commit()
            return updated
        except Exception:
            conn.rollback()
            raise
        finally:
            self._put_connection(conn)


# ---------------------------------------------------------------------------
# In-memory fallback (development only, not durable)
# ---------------------------------------------------------------------------


class InMemoryBiometricStorage:
    """Non-durable in-memory fallback for development.

    Data is lost on process restart. Do NOT use in production.

    All mutations are guarded by ``_lock`` so concurrent enrollments with the
    same idempotency key cannot both pass the uniqueness check and insert
    distinct references (check-then-act race).
    """

    def __init__(self) -> None:
        self._store: dict[str, BiometricEmbedding] = {}
        self._idempotency_index: dict[str, str] = {}
        self._lock = threading.Lock()

    def store(
        self,
        reference: str,
        vector: bytes,
        dimension: int,
        model_version: str,
        idempotency_key: str,
        request_fingerprint: str,
        ai_facts: str,
    ) -> None:
        if not reference:
            raise ValueError("reference must not be empty.")
        if not model_version:
            raise ValueError("model_version must not be empty.")
        if not idempotency_key:
            raise ValueError("idempotency_key must not be empty.")
        if not request_fingerprint:
            raise ValueError("request_fingerprint must not be empty.")
        if not ai_facts:
            raise ValueError("ai_facts must not be empty.")
        if dimension <= 0:
            raise ValueError(f"dimension must be positive, got {dimension}.")
        expected_len = dimension * 4  # float32 = 4 bytes
        if len(vector) != expected_len:
            raise ValueError(
                f"vector bytes length {len(vector)} does not match "
                f"dimension {dimension} (expected {expected_len} bytes)."
            )
        embedding = BiometricEmbedding(
            reference=reference,
            vector=vector,
            dimension=dimension,
            model_version=model_version,
            status="active",
            idempotency_key=idempotency_key,
            request_fingerprint=request_fingerprint,
            ai_facts=ai_facts,
            enrolled_at="",
            created_at="",
            updated_at="",
        )
        with self._lock:
            if reference in self._store:
                raise ValueError(f"Reference already exists: {reference}")
            if idempotency_key in self._idempotency_index:
                raise ValueError(f"Idempotency key already exists: {idempotency_key}")
            self._store[reference] = embedding
            self._idempotency_index[idempotency_key] = reference

    def get_by_reference(self, reference: str) -> BiometricEmbedding | None:
        with self._lock:
            return self._store.get(reference)

    def get_by_idempotency_key(self, idempotency_key: str) -> BiometricEmbedding | None:
        with self._lock:
            ref = self._idempotency_index.get(idempotency_key)
            if ref is None:
                return None
            return self._store.get(ref)

    def delete_by_reference(self, reference: str) -> bool:
        with self._lock:
            embedding = self._store.pop(reference, None)
            if embedding is not None:
                self._idempotency_index.pop(embedding.idempotency_key, None)
                return True
            return False

    def update_status(self, reference: str, status: str) -> bool:
        with self._lock:
            embedding = self._store.get(reference)
            if embedding is None:
                return False
            self._store[reference] = BiometricEmbedding(
                reference=embedding.reference,
                vector=embedding.vector,
                dimension=embedding.dimension,
                model_version=embedding.model_version,
                status=status,
                idempotency_key=embedding.idempotency_key,
                request_fingerprint=embedding.request_fingerprint,
                ai_facts=embedding.ai_facts,
                enrolled_at=embedding.enrolled_at,
                created_at=embedding.created_at,
                updated_at=embedding.updated_at,
            )
            return True


# ---------------------------------------------------------------------------
# Factory
# ---------------------------------------------------------------------------


def create_storage(database_url: str | None = None) -> BiometricStorage:
    """Create the appropriate storage backend.

    If ``database_url`` is provided, use PostgreSQL. Otherwise fall back
    to the non-durable in-memory store (development only).
    """
    if database_url is None:
        settings = get_settings()
        database_url = settings.biometric_database_url

    # Treat empty/whitespace-only values as unset so CI can force the
    # deterministic in-memory store via AI_BIOMETRIC_DATABASE_URL=''.
    if isinstance(database_url, str) and not database_url.strip():
        database_url = None

    if database_url is not None:
        return PostgreSQLBiometricStorage(database_url)

    logger.warning(
        "BIOMETRIC_DATABASE_URL is not set. Using non-durable in-memory storage. "
        "Embeddings will be lost on restart."
    )
    return InMemoryBiometricStorage()


# ---------------------------------------------------------------------------
# Reference generation
# ---------------------------------------------------------------------------


def generate_reference() -> str:
    """Generate an opaque, unpredictable embedding reference.

    Uses UUIDv4. Contains no employee identity, model version, or
    timestamp encoding.
    """
    return uuid.uuid4().hex


def validate_reference(reference: str) -> None:
    """Validate that a reference string is well-formed."""
    if not reference:
        raise ValueError("Embedding reference must not be empty.")
    if len(reference) < 32:
        raise ValueError(
            f"Embedding reference is too short ({len(reference)} chars). "
            "Expected at least 32 characters."
        )
    # UUIDv4 hex is 32 chars. Reject anything that looks like it might
    # contain semantic information (e.g., colons, employee IDs).
    if ":" in reference:
        raise ValueError("Embedding reference must not contain colons.")
