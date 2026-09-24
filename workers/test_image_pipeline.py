"""Compatibility smoke for the Python worker image pipeline."""

from __future__ import annotations

import unittest
from pathlib import Path
from tempfile import TemporaryDirectory

from PIL import Image, ImageDraw

from sanitize import strip_image_metadata
from transcode import to_webp
from watermark import watermark_image


class WorkerImagePipelineTest(unittest.TestCase):
    def test_pillow_pipeline_strips_exif_and_builds_derivatives(self) -> None:
        """Verify orientation, metadata removal, WEBP output, and watermark pixels."""
        with TemporaryDirectory(prefix="grindflow-worker-ci-") as tmp:
            root = Path(tmp)
            source = root / "source.jpg"
            clean = root / "clean.jpg"
            webp = root / "web.webp"
            marked = root / "marked.jpg"

            exif = Image.Exif()
            exif[0x0112] = 6
            exif[0x010F] = "GrindFlow CI Camera"

            source_image = Image.new("RGB", (480, 240))
            draw = ImageDraw.Draw(source_image)
            draw.rectangle((0, 0, 239, 119), fill=(220, 30, 30))
            draw.rectangle((240, 0, 479, 119), fill=(30, 220, 30))
            draw.rectangle((0, 120, 239, 239), fill=(30, 30, 220))
            draw.rectangle((240, 120, 479, 239), fill=(220, 220, 30))
            source_image.save(source, "JPEG", exif=exif, quality=95)

            strip_image_metadata(source, clean)

            with Image.open(clean) as image:
                self.assertEqual((240, 480), image.size)
                self.assertEqual(0, len(image.getexif()))

                expected_pixels = {
                    (60, 60): (30, 30, 220),
                    (180, 60): (220, 30, 30),
                    (60, 420): (220, 220, 30),
                    (180, 420): (30, 220, 30),
                }
                for point, expected in expected_pixels.items():
                    actual = image.getpixel(point)
                    self.assertLess(
                        sum(abs(actual[channel] - expected[channel]) for channel in range(3)),
                        150,
                    )

            to_webp(clean, webp, max_width=100)
            with Image.open(webp) as image:
                self.assertEqual("WEBP", image.format)
                self.assertEqual((100, 200), image.size)

            watermark_image(clean, marked, "grindflow-ci")
            with Image.open(marked) as image:
                self.assertEqual("JPEG", image.format)
                self.assertEqual((240, 480), image.size)

                expected_background = (30, 220, 30)
                changed_pixels = 0
                for y in range(440, 480):
                    for x in range(120, 240):
                        pixel = image.getpixel((x, y))
                        distance = sum(
                            abs(pixel[channel] - expected_background[channel])
                            for channel in range(3)
                        )
                        if distance > 120:
                            changed_pixels += 1

                self.assertGreater(changed_pixels, 50)


if __name__ == "__main__":
    unittest.main()
