"""Tests del contrato documental offline #653; no hacen llamadas remotas."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOCUMENT = ROOT / "docs" / "launch" / "a11y-offline-audit.md"
LISTA = ROOT / "docs" / "lista-salida-produccion.md"
FILA = "Teclado, foco, móvil y errores en journey real"


class DocContractTests(unittest.TestCase):
    def setUp(self) -> None:
        self.assertTrue(DOCUMENT.is_file(), "Falta el documento de auditoría offline")
        self.content = DOCUMENT.read_text(encoding="utf-8")

    def test_existe_y_tiene_secciones(self) -> None:
        for heading in (
            "## Alcance y método",
            "## Evidencia de plantillas y componentes",
            "## Hallazgos y propuestas WCAG 2.2 AA",
            "## Pruebas pendientes y responsables",
            "## Puerta de salida y reversión",
        ):
            with self.subTest(heading=heading):
                self.assertIn(heading, self.content)
        for label in ("login", "Subir material", "Programar", "Preparar/publicar"):
            self.assertIn(label.casefold(), self.content.casefold())
        for keyword in ("contraste", "foco", "errores", "WCAG 2.2 AA"):
            self.assertIn(keyword, self.content)
        self.assertIn("symfony/templates/identity/login.html.twig:5-22", self.content)
        self.assertIn("symfony/frontend/admin/VaultPanel.tsx:703-769", self.content)

    def test_sin_datos_sensibles(self) -> None:
        patterns = (
            r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----",
            r"\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{20,}\b",
            r"\bsk-[A-Za-z0-9]{16,}\b",
            r"(?i)\b(?:password|api_key|access_token|client_secret)\s*[:=]\s*['\"][^'\"]+",
            r"https?://[^\s/@]+:[^\s/@]+@",
            r"(?i)\bBearer\s+[A-Za-z0-9._-]{20,}",
            r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}",
        )
        for pattern in patterns:
            with self.subTest(pattern=pattern):
                self.assertNotRegex(self.content, pattern)

    def test_estado_honesto(self) -> None:
        for tag in ("PREPARACIÓN OFFLINE", "UNKNOWN", "NO ENSAYADO", "PROPUESTA", "no autoriza go-live"):
            self.assertIn(tag, self.content)
        self.assertIn("no se modificaron componentes", self.content.casefold())
        self.assertNotRegex(self.content, r"(?im)^\*\*Estado:\*\*\s*(?:PASS|GREEN|APROBADO)\b")

    def test_enlaza_fila_de_salida(self) -> None:
        self.assertTrue(LISTA.is_file(), "Falta la lista canónica")
        matches = [line for line in LISTA.read_text(encoding="utf-8").splitlines() if FILA in line]
        self.assertEqual(len(matches), 1, "Fila de accesibilidad ausente o duplicada")
        self.assertIn("DEL_DUEÑO", matches[0])
        self.assertIn(FILA, self.content)
        self.assertIn("../lista-salida-produccion.md", self.content)
        self.assertIn("DEL_DUEÑO", self.content)
        self.assertIn("#344", self.content)


if __name__ == "__main__":
    unittest.main()
