"""Contracts for rollback/shutdown guide #652; no production I/O."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/launch/rollback-shutdown-runbook.md"
LISTA = ROOT / "docs/lista-salida-produccion.md"
FILA = "Rollback del release anterior con SHA conservado"
APAGADO = "Deshabilitar publicación, detener cron y revocar accesos"
SECTIONS = (
    "## 0. Puertas de autoridad y preparación (NO EJECUTADO)",
    "## 1. Congelar publicación y escrituras (NO EJECUTADO)",
    "## 2. Detener cron y escritores (NO EJECUTADO)",
    "## 3. Conservar identidad de releases y decidir rollback (NO EJECUTADO)",
    "## 4. Revocar accesos y aislar el incidente (NO EJECUTADO)",
    "## 5. Verificación posterior y criterio de éxito (NO EJECUTADO)",
    "## 6. Fallo, recuperación y reapertura (NO EJECUTADO)",
    "## 7. Pruebas y mantenimiento del documento (OFFLINE)",
)
SENSITIVE = (
    r"ghp_[A-Za-z0-9]{12,}",
    r"github_pat_[A-Za-z0-9_]{12,}",
    r"sk-[A-Za-z0-9]{16,}",
    r"-----BEGIN [A-Z ]*PRIVATE KEY-----",
    r"(?i)\b(?:password|passwd|api_key|secret_key)\s*[:=]\s*[^\s<>]{8,}",
    r"(?i)\b(?:authorization|cookie|set-cookie)\s*:\s*\S+",
    r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b",
    r"/home/u\d{6,}/",
)
STATE_REQUIRED = ("NO EJECUTADO", "NO ENSAYADO", "UNKNOWN", "PROPUESTA",
                  "NO-GO", "DEL_DUEÑO")


def estado_honesto(text: str) -> bool:
    if any(marker not in text for marker in STATE_REQUIRED):
        return False
    if not text.startswith("# GrindFlow · rollback del release y apagado controlado"):
        return False
    # A fabricated productive status must fail even if the safe disclaimer remains.
    return re.search(
        r"(?im)^\s*(?:estado(?:\s+productivo)?|resultado(?:\s+productivo)?|go-live)"
        r"\s*:\s*(?:EJECUTADO|ENSAYADO|GO|GREEN|HEALTHY|CURRENT|"
        r"VALIDATED IN PRODUCTION)\s*$",
        text,
    ) is None


class DocContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.doc = DOC.read_text(encoding="utf-8")
        cls.lista = LISTA.read_text(encoding="utf-8")

    def test_existe_y_tiene_secciones(self) -> None:
        self.assertTrue(DOC.is_file())
        for heading in SECTIONS:
            with self.subTest(heading=heading):
                self.assertEqual(self.doc.count(heading), 1)
        for term in ("SHA", "symlink", "publicación", "cron",
                     "revocar", "Comprobación posterior", "Salida esperada",
                     "health", "backup", "NO-GO"):
            with self.subTest(term=term):
                self.assertIn(term.lower(), self.doc.lower())
        self.assertGreaterEqual(self.doc.count("~~~sh"), 2)
        for path in ("../PRODUCTION-RECOVERY.md", "../DEPLOY-HOSTINGER.md",
                     "../../ops/factory/adapter.py"):
            self.assertIn(path, self.doc)

    def test_sin_datos_sensibles(self) -> None:
        for regex in SENSITIVE:
            with self.subTest(regex=regex):
                self.assertIsNone(re.search(regex, self.doc))
        for forbidden in ("curl -u ", "ssh -i ", "artisan migrate --force",
                          "migrate:fresh", "GITHUB_TOKEN=", "DEPLOY_SSH_KEY="):
            self.assertNotIn(forbidden, self.doc)
        # No live invocation to change state should appear as a runnable code block.
        for snippet in self.doc.split("~~~sh")[1:]:
            code = snippet.split("~~~", 1)[0]
            self.assertNotRegex(code, r"(?im)\b(?:ssh|curl|rm|chmod|artisan|"
                                      r"ops/factory/rollback|gh workflow run)\b")

    def test_estado_honesto(self) -> None:
        self.assertTrue(estado_honesto(self.doc))
        self.assertFalse(estado_honesto(self.doc.replace("NO EJECUTADO", "EJECUTADO")))
        self.assertFalse(estado_honesto(self.doc + "\nEstado: VALIDATED IN PRODUCTION\n"))
        self.assertFalse(estado_honesto(self.doc + "\nResultado productivo: GO\n"))
        self.assertIn("no certifica", self.doc.lower())
        self.assertIn("no edita ni promociona", self.doc.lower())

    def test_enlaza_fila_de_salida(self) -> None:
        self.assertIn("](../lista-salida-produccion.md)", self.doc)
        for name in (FILA, APAGADO):
            with self.subTest(fila=name):
                rows = [line for line in self.lista.splitlines()
                        if line.startswith("|") and "| " + name + " |" in line]
                self.assertEqual(len(rows), 1)
                cells = [value.strip() for value in rows[0].strip().strip("|").split("|")]
                self.assertGreaterEqual(len(cells), 4)
                self.assertEqual(cells[2], "DEL_DUEÑO")
                self.assertIn(name, self.doc)
        self.assertIn("continúan **DEL_DUEÑO**", self.doc)
        self.assertIn("Validar terminal SUCCESS", self.doc)


if __name__ == "__main__":
    unittest.main()
