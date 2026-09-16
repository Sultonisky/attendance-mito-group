# Model Assets

This directory contains ONNX model weights for the FastAPI face AI pipeline.

## Provisioning

Model weights are **external assets** and are **not** committed to this repository.

Place the required model files directly in this directory:

| Key | Filename | Purpose |
|-----|----------|---------|
| `scrfd` | `det_500m.onnx` | SCRFD-500M face detector + 5-point landmarks |
| `arcface` | `w600k_mbf.onnx` | ArcFace 512-D face embedding model |
| `liveness` | `minifasnet_v2.onnx` | MiniFASNetV2 anti-spoofing liveness model |

## Configuration

The model directory path is configured via `AI_MODEL_DIR` in `.env`.

The pipeline version is configured via `AI_MODEL_VERSION` in `.env`.

## Missing Assets

If a required model file is missing, the application will raise an explicit
`ModelNotFound` error at load time and return a 503 response during inference.

The application will **not** silently substitute a fake or development model
when production inference is expected.

## License / Provenance

Model weights must be sourced and licensed separately.

Before using any model weight in production:

1. Verify the source and license terms.
2. Confirm redistribution rights if the weights are bundled with deployments.
3. Document the license obligations in this project's compliance records.

**Current status: UNVERIFIED — model weights are not present in this repository.**

## Reference

These models correspond to the reference implementation in `PRESENSI-main/mito/`:

- SCRFD: InsightFace `buffalo_sc` release (`det_500m.onnx`, ~2.5 MB)
- ArcFace: InsightFace `buffalo_sc` release (`w600k_mbf.onnx`, ~13.6 MB)
- MiniFASNetV2: minivision / garciafido (`minifasnet_v2.onnx`, ~1.7 MB)

See `PRESENSI-main/README.md` for preprocessing details and empirical validation notes.
