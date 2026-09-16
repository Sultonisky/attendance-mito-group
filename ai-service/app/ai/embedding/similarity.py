"""Cosine similarity utility for biometric embeddings.

Provides a pure mathematical cosine similarity implementation for
comparing embedding vectors.

This module contains no business logic, no thresholds, and no
verification decisions. It is a pure math utility.
"""

from __future__ import annotations

import logging

import numpy as np

logger = logging.getLogger(__name__)


def cosine_similarity(a: np.ndarray, b: np.ndarray) -> float:
    """Compute cosine similarity between two embeddings.

    For L2-normalized vectors, cosine similarity is equivalent to the
    dot product. This implementation works for arbitrary vectors by
    computing the full cosine formula.

    Args:
        a: First embedding vector.
        b: Second embedding vector. Must have the same shape as ``a``.

    Returns:
        Cosine similarity in the range ``[-1.0, 1.0]``.

    Raises:
        ValueError: If the vectors have incompatible shapes.
        ValueError: If either vector has zero norm.
        ValueError: If either vector contains NaN or Inf.
    """
    a = np.asarray(a, dtype=np.float32).reshape(-1)
    b = np.asarray(b, dtype=np.float32).reshape(-1)

    if a.shape != b.shape:
        raise ValueError(
            f"Embedding dimension mismatch: {a.shape} vs {b.shape}."
        )

    if a.shape[0] == 0:
        raise ValueError("Cannot compute cosine similarity for empty embeddings.")

    if not np.all(np.isfinite(a)) or not np.all(np.isfinite(b)):
        raise ValueError("Embeddings must contain only finite values.")

    norm_a = float(np.linalg.norm(a))
    norm_b = float(np.linalg.norm(b))

    if norm_a == 0.0 or norm_b == 0.0:
        raise ValueError(
            "Cannot compute cosine similarity for zero-norm embeddings."
        )

    similarity = float(np.dot(a, b) / (norm_a * norm_b))

    # Clamp tiny floating-point overshoot without changing semantics.
    if similarity > 1.0:
        similarity = 1.0
    elif similarity < -1.0:
        similarity = -1.0

    return similarity
