"""Regresiones del incidente GrindFlow #421: selección segura de MP4 en Vault móvil.

Estas pruebas inspeccionan el contrato del caso Playwright; la aceptación
funcional requiere además ejecutar Chromium real en symfony-preview.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
E2E = ROOT / "symfony" / "tests" / "e2e" / "preview.spec.mjs"
CASE_NAME = "S2 mobile Vault renders private MP4 controls and fails safely on undecodable media"


class VaultMp4SelectionCIContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        source = E2E.read_text(encoding="utf-8")
        marker = "test('" + CASE_NAME + "'"
        if source.count(marker) != 1:
            raise AssertionError("El escenario MP4 debe existir exactamente una vez")
        start = source.index(marker)
        rest = source[start:]
        following = re.search(r"(?m)^test\(", rest[len(marker):])
        cls.case = rest[:len(marker) + following.start()] if following else rest

    def test_second_mp4_selection_waits_for_refreshed_input(self):
        case = self.case
        first_file = "new File([new Uint8Array(bytes)], 'clip-sin-mime.mp4', { type: '' })"
        first_saved = "await expect(page.getByText('clip-sin-mime.mp4', { exact: true })).toBeVisible();"
        ready = "await expect(input).toBeEnabled();"
        second = "await input.setInputFiles({\n    name: 'clip-prueba.mp4',"
        second_saved = "await expect(page.getByText('clip-prueba.mp4', { exact: true })).toBeVisible();"
        for expected in (first_file, first_saved, ready, second, second_saved,
                         "mimeType: 'application/octet-stream'",
                         "await page.getByRole('button', { name: 'Guardar 1 archivo' }).click();"):
            self.assertIn(expected, case)
        self.assertEqual(case.count(ready), 1, "La espera pertenece a la segunda selección")
        self.assertLess(case.index(first_file), case.index(first_saved))
        self.assertLess(case.index(first_saved), case.index(ready))
        self.assertLess(case.index(ready), case.index(second))
        self.assertLess(case.index(second), case.index(second_saved))
        # Comprobar cada selección por separado: un conteo global puede
        # esconder la ausencia de controles tras la segunda selección.
        self.assertEqual(case.count(second), 1, "La segunda selección debe ser única")
        before_second, after_second = case.split(second, 1)
        for assertion in (
            "await expect(page.locator('.vault-selected-mark')).toHaveCount(1);",
            "await expect(page.locator('.vault-selected-files video')).toHaveCount(0);",
            "await expect(page.locator('.vault-selected-files [role=\"alert\"]')).toHaveCount(0);",
        ):
            with self.subTest(assertion=assertion):
                self.assertIn(assertion, before_second)
                self.assertIn(assertion, after_second)
        self.assertIn("expect(videoUploads).toBe(2);", case)

    def test_private_mp4_error_and_no_autoplay_gates_remain(self):
        case = self.case
        for expected in (
            "await page.setViewportSize({ width: 360, height: 740 });",
            "await expect(preview).toHaveCount(1);",
            "await expect(preview).toHaveAttribute('controls', '');",
            "await expect(preview).toHaveAttribute('preload', 'metadata');",
            "await expect(preview).toHaveAttribute('src', '/api/admin/vault/' + id + '/preview');",
            "expect(await preview.getAttribute('autoplay')).toBeNull();",
            "La vista previa no está disponible. Comprueba la integridad del original antes de descargarlo.",
            "await expect(preview).toBeHidden();",
            "expect(request.headers()['x-csrf-token']).toBe('video-upload-test');",
        ):
            with self.subTest(contract=expected):
                self.assertIn(expected, case)


if __name__ == "__main__":
    unittest.main()
