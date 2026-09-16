"""Model asset registry for the FastAPI AI service.

Defines expected model files, resolves paths, and reports availability.
Does NOT download or generate model weights.
"""

from __future__ import annotations

from enum import Enum
from pathlib import Path

from app.core.config import get_settings


class ModelAssetStatus(str, Enum):
    """Availability status of a model asset."""

    AVAILABLE = "available"
    MISSING = "missing"
    INVALID = "invalid"


class ModelAsset:
    """Describes a single model asset file expected by the pipeline."""

    def __init__(
        self,
        key: str,
        filename: str,
        required: bool = True,
        description: str = "",
    ) -> None:
        self.key = key
        self.filename = filename
        self.required = required
        self.description = description

    def resolve(self, model_dir: Path) -> Path:
        """Return the expected filesystem path for this asset."""
        return model_dir / self.filename

    def status(self, model_dir: Path) -> ModelAssetStatus:
        """Return availability status for this asset."""
        path = self.resolve(model_dir)
        if not path.exists():
            return ModelAssetStatus.MISSING
        if not path.is_file():
            return ModelAssetStatus.INVALID
        if path.stat().st_size == 0:
            return ModelAssetStatus.INVALID
        return ModelAssetStatus.AVAILABLE


class ModelAssetRegistry:
    """Registry of expected model assets for the face AI pipeline."""

    def __init__(self, model_dir: Path, pipeline_version: str) -> None:
        self._model_dir = model_dir
        self._pipeline_version = pipeline_version
        self._assets: dict[str, ModelAsset] = {}

    @property
    def model_dir(self) -> Path:
        """Root directory containing model assets."""
        return self._model_dir

    @property
    def pipeline_version(self) -> str:
        """Pipeline version string for the registered model stack."""
        return self._pipeline_version

    def register(
        self,
        key: str,
        filename: str,
        required: bool = True,
        description: str = "",
    ) -> None:
        """Register a model asset descriptor."""
        self._assets[key] = ModelAsset(key, filename, required, description)

    def resolve(self, key: str) -> Path:
        """Resolve the filesystem path for a registered asset."""
        return self._assets[key].resolve(self._model_dir)

    def get(self, key: str) -> ModelAsset:
        """Return the registered asset descriptor."""
        return self._assets[key]

    def status(self, key: str) -> ModelAssetStatus:
        """Return availability status for a registered asset."""
        return self._assets[key].status(self._model_dir)

    def availability_report(self) -> dict[str, ModelAssetStatus]:
        """Return availability status for all registered assets."""
        return {key: asset.status(self._model_dir) for key, asset in self._assets.items()}

    def missing_required(self) -> list[str]:
        """Return keys of required assets that are not available."""
        return [
            key
            for key, asset in self._assets.items()
            if asset.required and asset.status(self._model_dir) != ModelAssetStatus.AVAILABLE
        ]


def create_default_registry() -> ModelAssetRegistry:
    """Create the default model asset registry for the face pipeline.

    The expected model files are:

    - ``det_500m.onnx``   : SCRFD-500M face detector + 5-point landmarks
    - ``w600k_mbf.onnx``  : ArcFace 512-D face embedding model
    - ``minifasnet_v2.onnx`` : MiniFASNetV2 anti-spoofing liveness model
    """
    settings = get_settings()
    registry = ModelAssetRegistry(
        model_dir=Path(settings.model_dir),
        pipeline_version=settings.model_version,
    )

    registry.register(
        key="scrfd",
        filename="det_500m.onnx",
        required=True,
        description="SCRFD-500M face detector with 5-point landmarks (InsightFace buffalo_sc).",
    )
    registry.register(
        key="arcface",
        filename="w600k_mbf.onnx",
        required=True,
        description="ArcFace w600k_mbf 512-D face embedding model (InsightFace buffalo_sc).",
    )
    registry.register(
        key="liveness",
        filename="minifasnet_v2.onnx",
        required=True,
        description="MiniFASNetV2 anti-spoofing liveness model (ensemble 2.7x + 4.0x crop).",
    )

    return registry
