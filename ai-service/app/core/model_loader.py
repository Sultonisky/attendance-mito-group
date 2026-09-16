"""Model loading abstraction for ONNX Runtime inference.

Provides lazy loading, session caching, and explicit error types for
model infrastructure failures. Does NOT implement actual inference.
"""

from __future__ import annotations

import logging
from typing import TYPE_CHECKING

from app.core.config import get_settings

from app.core.model_assets import ModelAsset, ModelAssetRegistry, ModelAssetStatus

try:
    import onnxruntime as ort
except ImportError:
    ort = None  # type: ignore[assignment,misc]

if TYPE_CHECKING:
    pass

logger = logging.getLogger(__name__)


class ModelLoadError(Exception):
    """Base class for model loading failures."""


class ModelNotFound(ModelLoadError):
    """Raised when a required model asset file is missing."""


class ModelInvalid(ModelLoadError):
    """Raised when a model asset exists but cannot be loaded."""


class ModelUnavailable(ModelLoadError):
    """Raised when a model asset is unavailable for inference."""


class ModelLoader:
    """Lazy-loading ONNX Runtime session manager.

    Sessions are cached after first load. Subsequent requests for the same
    model key return the cached session.
    """

    def __init__(self, registry: ModelAssetRegistry) -> None:
        self._registry = registry
        self._sessions: dict[str, ort.InferenceSession] = {}

    def load(self, key: str) -> ort.InferenceSession:
        """Load (or return cached) ONNX Runtime session for the given model key.

        Args:
            key: Registered model asset key (e.g. ``"scrfd"``).

        Returns:
            Cached ``onnxruntime.InferenceSession``.

        Raises:
            ModelNotFound: model file is missing from the asset directory.
            ModelInvalid: model file exists but cannot be loaded.
            ModelUnavailable: model is unavailable for any other reason.
        """
        if key in self._sessions:
            return self._sessions[key]

        asset = self._registry.get(key)
        path = self._registry.resolve(key)
        status = asset.status(self._registry.model_dir)

        if status is ModelAssetStatus.MISSING:
            raise ModelNotFound(
                f"Model asset '{key}' is missing. "
                f"Expected path: {path}. "
                f"See models/README.md for provisioning instructions."
            )
        if status is ModelAssetStatus.INVALID:
            raise ModelInvalid(
                f"Model asset '{key}' at {path} is invalid. "
                f"Check that the file is a valid ONNX model."
            )

        try:
            if ort is None:
                raise ModelUnavailable("onnxruntime is not installed.")
            session = ort.InferenceSession(
                str(path),
                providers=["CPUExecutionProvider"],
            )
        except ModelLoadError:
            raise
        except Exception as exc:  # noqa: BLE001
            raise ModelInvalid(
                f"Failed to load model asset '{key}' from {path}: {exc}"
            ) from exc

        self._sessions[key] = session
        logger.info(
            "Model loaded: key=%s path=%s providers=%s",
            key,
            path,
            session.get_providers(),
        )
        return session

    def is_loaded(self, key: str) -> bool:
        """Return whether the model session is currently cached."""
        return key in self._sessions

    def unload(self, key: str) -> None:
        """Release a cached session."""
        self._sessions.pop(key, None)

    def availability(self) -> dict[str, ModelAssetStatus]:
        """Return availability status for all registered assets."""
        return self._registry.availability_report()
