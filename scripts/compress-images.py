from __future__ import annotations

import argparse
import re
from pathlib import Path

from PIL import Image, ImageOps


SUPPORTED_EXTENSIONS = {".jpg", ".jpeg", ".png", ".webp", ".bmp", ".tif", ".tiff"}


def safe_stem(name: str) -> str:
    stem = Path(name).stem
    stem = re.sub(r"[^A-Za-z0-9._-]+", "-", stem).strip("-._")
    return stem or "image"


def iter_images(input_path: Path) -> list[Path]:
    if input_path.is_file():
        return [input_path] if input_path.suffix.lower() in SUPPORTED_EXTENSIONS else []
    return [
        path
        for path in sorted(input_path.rglob("*"))
        if path.is_file() and path.suffix.lower() in SUPPORTED_EXTENSIONS
    ]


def flatten_for_jpeg(image: Image.Image) -> Image.Image:
    if image.mode in ("RGBA", "LA") or (
        image.mode == "P" and "transparency" in image.info
    ):
        background = Image.new("RGB", image.size, (255, 255, 255))
        background.paste(image.convert("RGBA"), mask=image.convert("RGBA").getchannel("A"))
        return background
    return image.convert("RGB")


def resize_image(image: Image.Image, max_width: int) -> Image.Image:
    if image.width <= max_width:
        return image.copy()
    ratio = max_width / float(image.width)
    height = max(1, round(image.height * ratio))
    return image.resize((max_width, height), Image.Resampling.LANCZOS)


def save_jpeg_under_target(
    image: Image.Image,
    output_path: Path,
    quality: int,
    min_quality: int,
    target_bytes: int,
) -> int:
    current_quality = quality
    while True:
        image.save(
            output_path,
            "JPEG",
            quality=current_quality,
            optimize=True,
            progressive=True,
        )
        size = output_path.stat().st_size
        if size <= target_bytes or current_quality <= min_quality:
            return current_quality
        current_quality -= 5


def compress_one(
    source: Path,
    output_dir: Path,
    max_width: int,
    quality: int,
    min_quality: int,
    target_kb: int,
) -> tuple[Path, int, int, int]:
    output_dir.mkdir(parents=True, exist_ok=True)
    output_path = output_dir / f"{safe_stem(source.name)}.jpg"

    with Image.open(source) as raw:
        image = ImageOps.exif_transpose(raw)
        image = resize_image(image, max_width)
        image = flatten_for_jpeg(image)
        used_quality = save_jpeg_under_target(
            image=image,
            output_path=output_path,
            quality=quality,
            min_quality=min_quality,
            target_bytes=target_kb * 1024,
        )

    return output_path, source.stat().st_size, output_path.stat().st_size, used_quality


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Compress website images for Pages CMS media uploads."
    )
    parser.add_argument(
        "input",
        nargs="?",
        default="image-input",
        help="Input file or folder. Default: image-input",
    )
    parser.add_argument(
        "-o",
        "--output",
        default="image-output",
        help="Output folder. Default: image-output",
    )
    parser.add_argument(
        "--max-width",
        type=int,
        default=1800,
        help="Resize images wider than this. Default: 1800",
    )
    parser.add_argument(
        "--target-kb",
        type=int,
        default=1000,
        help="Try to keep each output image under this size. Default: 1000",
    )
    parser.add_argument(
        "--quality",
        type=int,
        default=82,
        help="Starting JPEG quality. Default: 82",
    )
    parser.add_argument(
        "--min-quality",
        type=int,
        default=50,
        help="Lowest JPEG quality if target size is hard to reach. Default: 50",
    )
    args = parser.parse_args()

    input_path = Path(args.input)
    output_dir = Path(args.output)
    images = iter_images(input_path)

    if not images:
        print(f"No supported images found in {input_path}")
        return

    print(f"Compressing {len(images)} image(s)...")
    for source in images:
        output_path, before, after, used_quality = compress_one(
            source=source,
            output_dir=output_dir,
            max_width=args.max_width,
            quality=args.quality,
            min_quality=args.min_quality,
            target_kb=args.target_kb,
        )
        print(
            f"{source.name} -> {output_path} "
            f"({before / 1024:.0f} KB -> {after / 1024:.0f} KB, q={used_quality})"
        )


if __name__ == "__main__":
    main()

