"""
Image utilities for the FastAPI service.

All image validation/decoding happens server-side using Pillow. The client
(must be Laravel) is never trusted for MIME type, dimensions, or content
validity — FastAPI validates the raw bytes independently.
"""

from io import BytesIO
from typing import Tuple

from PIL import Image, UnidentifiedImageError


# Allowed MIME types for face enrollment / verification images.
ALLOWED_MIME_TYPES = frozenset({
    "image/jpeg",
    "image/png",
    "image/webp",
})

# Allowed image format identifiers returned by Pillow's Image.format.
ALLOWED_FORMATS = frozenset({"JPEG", "PNG", "WEBP"})


class ImageValidationError(ValueError):
    """Raised when image bytes fail validation."""


def validate_and_decode_image(
    image_bytes: bytes,
    mime_type: str,
    max_size_bytes: int = 5_000_000,
    max_dimension: int = 4096,
) -> Image.Image:
    """Validate raw image bytes and decode to a Pillow Image.

    Checks performed (in order):
    1. Non-empty byte payload.
    2. MIME type allow-list (do not trust client MIME alone — Pillow
       re-validates the actual format).
    3. Size ceiling (prevents unbounded uploads).
    4. Pillow can decode the bytes (rejects corrupt / non-image data).
    5. Format is in the allow-list (guards against e.g. GIF masquerading as PNG).
    6. Dimensions are within bounds (decompression-bomb guard).

    Returns the decoded and verified ``Image.Image``.

    Raises ``ImageValidationError`` on any failure.
    """
    if not image_bytes:
        raise ImageValidationError("Image data is empty.")

    if len(image_bytes) > max_size_bytes:
        raise ImageValidationError(
            f"Image exceeds maximum size of {max_size_bytes} bytes "
            f"(received {len(image_bytes)} bytes)."
        )

    if mime_type not in ALLOWED_MIME_TYPES:
        raise ImageValidationError(
            f"Unsupported MIME type '{mime_type}'. "
            f"Allowed: {', '.join(sorted(ALLOWED_MIME_TYPES))}."
        )

    try:
        img = Image.open(BytesIO(image_bytes))
        img.load()
    except (UnidentifiedImageError, OSError) as exc:
        raise ImageValidationError(f"Unable to decode image: {exc}") from exc

    # Re-validate format from the actual decoded data, not the client MIME.
    if img.format is None or img.format.upper() not in ALLOWED_FORMATS:
        raise ImageValidationError(
            f"Image format '{img.format}' is not supported. "
            f"Allowed formats: {', '.join(sorted(ALLOWED_FORMATS))}."
        )

    width, height = img.size
    if width <= 0 or height <= 0:
        raise ImageValidationError("Image has invalid dimensions.")

    if width > max_dimension or height > max_dimension:
        raise ImageValidationError(
            f"Image dimensions {width}x{height} exceed maximum "
            f"of {max_dimension}x{max_dimension}."
        )

    return img


def normalize_image(img: Image.Image) -> Image.Image:
    """Convert to RGB and resize to a canonical dimension for consistent
    embedding generation.

    The canonical size keeps memory bounded and ensures hash stability.
    """
    if img.mode != "RGB":
        img = img.convert("RGB")

    # Resize to a fixed, modest resolution for embedding generation.
    canonical_size = (128, 128)
    return img.resize(canonical_size, Image.Resampling.LANCZOS)
