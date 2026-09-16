"""Tests for model asset registry and loader foundation.

These tests verify the model asset infrastructure without requiring
production ONNX weights. Real model inference is not tested here.
"""

from __future__ import annotations

import os
import unittest.mock
from pathlib import Path

import pytest

from app.core.config import get_settings
from app.core.model_assets import (
    ModelAsset,
    ModelAssetRegistry,
    ModelAssetStatus,
    create_default_registry,
)
from app.core.model_loader import (
    ModelInvalid,
    ModelLoader,
    ModelNotFound,
    ModelUnavailable,
)


# Ensure a known API key is set before importing app modules so the cached
# Settings picks it up on first access.
os.environ.setdefault("AI_API_KEY", "test-secret-key-123")

# Clear the settings cache so the env var above takes effect.
get_settings.cache_clear()


class TestModelAssetStatus:
    """ModelAsset availability checks."""

    def test_missing_when_file_absent(self, tmp_path: Path) -> None:
        asset = ModelAsset(key="test", filename="missing.onnx")
        assert asset.status(tmp_path) is ModelAssetStatus.MISSING

    def test_invalid_when_path_is_directory(self, tmp_path: Path) -> None:
        (tmp_path / "dir.onnx").mkdir()
        asset = ModelAsset(key="test", filename="dir.onnx")
        assert asset.status(tmp_path) is ModelAssetStatus.INVALID

    def test_available_when_file_exists(self, tmp_path: Path) -> None:
        (tmp_path / "model.onnx").write_bytes(b"fake onnx")
        asset = ModelAsset(key="test", filename="model.onnx")
        assert asset.status(tmp_path) is ModelAssetStatus.AVAILABLE

    def test_invalid_when_file_empty(self, tmp_path: Path) -> None:
        (tmp_path / "empty.onnx").write_bytes(b"")
        asset = ModelAsset(key="test", filename="empty.onnx")
        assert asset.status(tmp_path) is ModelAssetStatus.INVALID


class TestModelAssetRegistry:
    """ModelAssetRegistry behavior."""

    def test_resolve_path(self, tmp_path: Path) -> None:
        registry = ModelAssetRegistry(model_dir=tmp_path, pipeline_version="v1")
        registry.register("scrfd", "det_500m.onnx")
        assert registry.resolve("scrfd") == tmp_path / "det_500m.onnx"

    def test_missing_required_returns_keys(self, tmp_path: Path) -> None:
        registry = ModelAssetRegistry(model_dir=tmp_path, pipeline_version="v1")
        registry.register("scrfd", "det_500m.onnx", required=True)
        registry.register("align", "align.py", required=False)
        assert registry.missing_required() == ["scrfd"]

    def test_availability_report(self, tmp_path: Path) -> None:
        (tmp_path / "det_500m.onnx").write_bytes(b"onnx")
        registry = ModelAssetRegistry(model_dir=tmp_path, pipeline_version="v1")
        registry.register("scrfd", "det_500m.onnx")
        registry.register("arcface", "missing.onnx")
        report = registry.availability_report()
        assert report["scrfd"] is ModelAssetStatus.AVAILABLE
        assert report["arcface"] is ModelAssetStatus.MISSING

    def test_pipeline_version_property(self, tmp_path: Path) -> None:
        registry = ModelAssetRegistry(model_dir=tmp_path, pipeline_version="mito-face-v1")
        assert registry.pipeline_version == "mito-face-v1"


class TestCreateDefaultRegistry:
    """Default registry contains expected pipeline assets."""

    def test_has_expected_keys(self) -> None:
        registry = create_default_registry()
        assert "scrfd" in registry._assets
        assert "arcface" in registry._assets
        assert "liveness" in registry._assets

    def test_pipeline_version_from_settings(self) -> None:
        registry = create_default_registry()
        assert registry.pipeline_version == "mito-face-v1"

    def test_assets_are_required_by_default(self) -> None:
        registry = create_default_registry()
        for asset in registry._assets.values():
            assert asset.required is True


class TestModelLoader:
    """ModelLoader behavior without real ONNX files."""

    def test_returns_cached_session(self, tmp_path: Path) -> None:
        (tmp_path / "model.onnx").write_bytes(b"onnx")
        registry = ModelAssetRegistry(model_dir=tmp_path, pipeline_version="v1")
        registry.register("test", "model.onnx")
        loader = ModelLoader(registry)

        mock_session = object()
        loader._sessions["test"] = mock_session  # type: ignore[assignment]

        assert loader.load("test") is mock_session

    def test_raises_not_found_for_missing_asset(self, tmp_path: Path) -> None:
        registry = ModelAssetRegistry(model_dir=tmp_path, pipeline_version="v1")
        registry.register("test", "missing.onnx")
        loader = ModelLoader(registry)

        with pytest.raises(ModelNotFound):
            loader.load("test")

    def test_raises_invalid_for_directory_path(self, tmp_path: Path) -> None:
        (tmp_path / "dir.onnx").mkdir()
        registry = ModelAssetRegistry(model_dir=tmp_path, pipeline_version="v1")
        registry.register("test", "dir.onnx")
        loader = ModelLoader(registry)

        with pytest.raises(ModelInvalid):
            loader.load("test")

    def test_loads_session_with_cpu_provider(self, tmp_path: Path) -> None:
        (tmp_path / "model.onnx").write_bytes(b"onnx")
        registry = ModelAssetRegistry(model_dir=tmp_path, pipeline_version="v1")
        registry.register("test", "model.onnx")
        loader = ModelLoader(registry)

        mock_session = unittest.mock.MagicMock()
        mock_ort = unittest.mock.MagicMock()
        mock_ort.InferenceSession.return_value = mock_session

        with unittest.mock.patch("app.core.model_loader.ort", mock_ort):
            session = loader.load("test")
            assert session is mock_session
            mock_ort.InferenceSession.assert_called_once()
            args, kwargs = mock_ort.InferenceSession.call_args
            assert str(args[0]) == str(tmp_path / "model.onnx")
            assert kwargs["providers"] == ["CPUExecutionProvider"]

    def test_raises_invalid_on_ort_exception(self, tmp_path: Path) -> None:
        (tmp_path / "model.onnx").write_bytes(b"onnx")
        registry = ModelAssetRegistry(model_dir=tmp_path, pipeline_version="v1")
        registry.register("test", "model.onnx")
        loader = ModelLoader(registry)

        mock_ort = unittest.mock.MagicMock()
        mock_ort.InferenceSession.side_effect = RuntimeError("bad model")

        with unittest.mock.patch("app.core.model_loader.ort", mock_ort):
            with pytest.raises(ModelInvalid):
                loader.load("test")

    def test_is_loaded_tracks_state(self, tmp_path: Path) -> None:
        registry = ModelAssetRegistry(model_dir=tmp_path, pipeline_version="v1")
        loader = ModelLoader(registry)

        assert loader.is_loaded("test") is False
        loader._sessions["test"] = object()  # type: ignore[assignment]
        assert loader.is_loaded("test") is True

    def test_unload_removes_session(self, tmp_path: Path) -> None:
        registry = ModelAssetRegistry(model_dir=tmp_path, pipeline_version="v1")
        loader = ModelLoader(registry)
        loader._sessions["test"] = object()  # type: ignore[assignment]
        loader.unload("test")
        assert "test" not in loader._sessions

    def test_availability_delegates_to_registry(self, tmp_path: Path) -> None:
        (tmp_path / "model.onnx").write_bytes(b"onnx")
        registry = ModelAssetRegistry(model_dir=tmp_path, pipeline_version="v1")
        registry.register("test", "model.onnx")
        loader = ModelLoader(registry)

        report = loader.availability()
        assert report["test"] is ModelAssetStatus.AVAILABLE
