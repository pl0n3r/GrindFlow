"""Compatibility smoke for the Python worker image pipeline."""

from __future__ import annotations

import unittest
from pathlib import Path
from tempfile import TemporaryDirectory

from PIL import Image

from sanitize import strip_image_metadata
from transcode import to_webp
from watermark import watermark_image


class WorkerImagePipelineTest(unittest.TestCase):
    def test_pillow_pipeline_strips_exif_and_builds_derivatives(self) -> None:
        with TemporaryDirectory(prefix="grindflow-worker-ci-") as tmp:
            root = Path(tmp)
            source = root / "source.jpg"
            clean = root / "clean.jpg"
            webp = root / "web.webp"
            marked = root / "marked.jpg"

            exif = Image.Exif()
            exif[0x0112] = 6
            exif[0x010F] = "GrindFlow CI Camera"
            Image.new("RGB", (24, 12), (120, 40, 80)).save(
                source,
                "JPEG",
                exif=exif,
            )

            strip_image_metadata(source, clean)

            with Image.open(clean) as image:
                self.assertEqual((12, 24), image.size)
                self.assertEqual(0, len(image.getexif()))

            to_webp(clean, webp, max_width=10)
            with Image.open(webp) as image:
                self.assertEqual("WEBP", image.format)
                self.assertLessEqual(image.width, 10)

            watermark_image(clean, marked, "grindflow-ci")
            with Image.open(marked) as image:
                self.assertEqual("JPEG", image.format)
                self.assertEqual((12, 24), image.size)


if __name__ == "__main__":
    unittest.main()
