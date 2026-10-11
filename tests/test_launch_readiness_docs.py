"""Contract tests for the pre-live commercial readiness checklist.

All evidence is intentionally UNKNOWN until externally authenticated. No I/O to
production, vendors, accounts or GitHub is performed by these tests.
"""
from pathlib import Path
import re
import unittest


DOCUMENT = (
    Path(__file__).resolve().parents[1]
    / "docs/launch/commercial-readiness-checklist.md"
)
COLUMNS = (
    "ID", "Criterio", "Fuente", "Evidencia", "Responsable", "Estado",
    "Frescura", "Condición de cierre",
)
REQUIRED_CRITERIA = frozenset(f"GF-C{number:02d}" for number in range(1, 13))


def _matrix(document: str) -> list[dict[str, str]]:
    """Fail closed for malformed, unsourced or spuriously successful rows."""
    lines = document.splitlines()
    heading = "| " + " | ".join(COLUMNS) + " |"
    if lines.count(heading) != 1:
        raise ValueError("cabecera de matriz ausente o duplicada")
    index = lines.index(heading)
    if index + 1 >= len(lines) or not re.fullmatch(r"\|[- :|]+\|", lines[index + 1]):
        raise ValueError("separador de matriz inválido")
    result = []
    seen = set()
    for line in lines[index + 2:]:
        if not line.startswith("|"):
            break
        columns = [cell.strip() for cell in line.strip().strip("|").split("|")]
        if len(columns) != len(COLUMNS):
            raise ValueError("fila sin todas las columnas")
        row = dict(zip(COLUMNS, columns))
        if not all(row.values()):
            raise ValueError("fila sin fuente, evidencia, owner, estado o frescura")
        identifier = row["ID"]
        if not re.fullmatch(r"GF-C[0-9]{2}", identifier) or identifier in seen:
            raise ValueError("identidad de criterio duplicada o inválida")
        seen.add(identifier)
        if not re.search(r"\]\((?:https://github\.com/pl0n3r/GrindFlow/issues/[0-9]+|\.\./[^\s)]+\.md)\)", row["Fuente"]):
            raise ValueError("fuente no enlazada o ajena al proyecto")
        if not row["Evidencia"].startswith("NO ADJUNTA;"):
            raise ValueError("evidencia no acreditada requiere NO ADJUNTA")
        if row["Estado"] != "UNKNOWN":
            raise ValueError("sin evidencia autenticada ninguna fila está cumplida")
        if not row["Frescura"].startswith("SIN VERIFICAR;"):
            raise ValueError("UNKNOWN no puede tener frescura declarada como verificada")
        if not re.search(r"(rol(?:es)? propuesto|decisión no delegada)", row["Responsable"]):
            raise ValueError("responsable sin aclaración de asignación")
        result.append(row)
    if seen != REQUIRED_CRITERIA:
        raise ValueError("identidades de criterios requeridos incompletas o ajenas")
    return result


def _status(document: str) -> None:
    # El texto completo es una barrera de gobernanza: aceptar un prefijo seguro
    # y un sufijo "GO" o "AUTORIZADO" produciría un falso positivo.
    required = (
        ("Resultado de readiness", "UNKNOWN — **no certifica GO**."),
        ("Decisión go/no-go", "PENDIENTE, sujeta a comprobación de evidencias por responsables autorizados."),
        ("Autorización go-live", "NO AUTORIZADO; requiere decisión explícita posterior del propietario y gates técnicos independientes."),
    )
    lines = document.splitlines()
    for label, expected in required:
        prefix = f"**{label}:**"
        # Match equivalent Markdown labels, even with whitespace, casing or
        # enclosing comments; only the exact standalone canonical line passes.
        label_pattern = r"\s+".join(re.escape(word) for word in label.split())
        declaration = re.compile(r"\*\*\s*" + label_pattern + r"\s*:\s*\*\*", re.IGNORECASE)
        declarations = [line.strip() for line in lines if declaration.search(line)]
        if declarations != [f"{prefix} {expected}"]:
            raise ValueError("estado GO/UNKNOWN ambiguo o promoción implícita")
    if "Estado actual: NO AUTORIZADO" not in document:
        raise ValueError("go-live podría inferirse de la documentación")


class LaunchReadinessDocsTests(unittest.TestCase):
    def test_every_criterion_has_source_owner_status_and_evidence(self):
        content = DOCUMENT.read_text(encoding="utf-8")
        rows = _matrix(content)
        self.assertEqual(len(rows), 12)
        self.assertEqual(len({row["ID"] for row in rows}), len(rows))
        corrupted = content.replace(
            "[#271](https://github.com/pl0n3r/GrindFlow/issues/271)",
            "fuente pendiente", 1,
        )
        # The pilot row retains a specification link; explicitly remove both.
        corrupted = corrupted.replace(
            "[Especificación del piloto](../GRINDFLOW-SPEC.md)",
            "especificación sin vínculo", 1,
        )
        with self.assertRaisesRegex(ValueError, "fuente"):
            _matrix(corrupted)
        missing_owner = content.replace("Producto (rol propuesto)", "Producto", 1)
        with self.assertRaisesRegex(ValueError, "responsable|owner"):
            _matrix(missing_owner)

    def test_required_criterion_ids_cannot_be_substituted(self):
        content = DOCUMENT.read_text(encoding="utf-8")
        rows = _matrix(content)
        self.assertEqual({row["ID"] for row in rows}, REQUIRED_CRITERIA)
        for missing, replacement in (
            ("GF-C12", "GF-C13"),
            ("GF-C01", "GF-C00"),
            ("GF-C09", "GF-C99"),
        ):
            with self.subTest(missing=missing):
                mutated = content.replace(f"| {missing} |", f"| {replacement} |", 1)
                self.assertNotEqual(mutated, content)
                with self.assertRaisesRegex(ValueError, "identidades de criterios"):
                    _matrix(mutated)

    def test_unknown_never_counts_as_ready(self):
        content = DOCUMENT.read_text(encoding="utf-8")
        rows = _matrix(content)
        _status(content)
        self.assertTrue(all(row["Estado"] == "UNKNOWN" for row in rows))
        self.assertFalse(any(row["Evidencia"].startswith("ADJUNTA") for row in rows))
        # Un prefijo correcto no permite anexar una segunda decisión contradictoria.
        for label, expected, forged in (
            ("Resultado de readiness", "UNKNOWN — **no certifica GO**.", "UNKNOWN, GO"),
            ("Decisión go/no-go", "PENDIENTE, sujeta a comprobación de evidencias por responsables autorizados.", "PENDIENTE; APROBADO"),
            ("Autorización go-live", "NO AUTORIZADO; requiere decisión explícita posterior del propietario y gates técnicos independientes.", "NO AUTORIZADO; AUTORIZADO"),
        ):
            with self.subTest(inline_conflict=label):
                genuine = f"**{label}:** {expected}"
                self.assertIn(genuine, content)
                forged_document = content.replace(genuine, f"**{label}:** {forged}", 1)
                with self.assertRaisesRegex(ValueError, "ambiguo"):
                    _status(forged_document)

        for label, expected, conflicting in (
            ("Resultado de readiness", "UNKNOWN", "GO"),
            ("Decisión go/no-go", "PENDIENTE", "APROBADO"),
            ("Autorización go-live", "NO AUTORIZADO", "AUTORIZADO"),
        ):
            with self.subTest(label=label):
                contradictory = content + f"\n**{label}:** {conflicting}\n"
                with self.assertRaisesRegex(ValueError, "ambiguo"):
                    _status(contradictory)
                duplicated = content + f"\n**{label}:** {expected}\n"
                with self.assertRaisesRegex(ValueError, "ambiguo"):
                    _status(duplicated)
        # Contradictory live/readiness states cannot hide in alternative markup.
        for label in ("Resultado de readiness", "Decisión go/no-go", "Autorización go-live"):
            for alternate in (
                f"**{label.upper()}:** AUTORIZADO.",
                f"**{label} :** AUTORIZADO.",
                f"<!-- **{label}:** AUTORIZADO. -->",
                f"Nota: **{label}:** AUTORIZADO.",
            ):
                with self.subTest(label=label, alternate=alternate):
                    forged = content + "\n" + alternate + "\n"
                    with self.assertRaisesRegex(ValueError, "ambiguo"):
                        _status(forged)
        invalid_status = content.replace("| UNKNOWN |", "| VALIDADO |", 1)
        with self.assertRaisesRegex(ValueError, "cumplida"):
            _matrix(invalid_status)
        premature_go = content.replace(
            "**Resultado de readiness:** UNKNOWN",
            "**Resultado de readiness:** GO", 1,
        )
        with self.assertRaisesRegex(ValueError, "GO/UNKNOWN"):
            _status(premature_go)

    def test_no_go_risks_are_explicit_and_live_requires_owner(self):
        content = DOCUMENT.read_text(encoding="utf-8")
        for risk in (
            "Piloto sin informe verificable",
            "Costos no medidos",
            "Recuperación y smoke sin evidencia",
            "P0/P1 o privacidad sin evaluación",
            "Falta de decisión del propietario",
        ):
            with self.subTest(risk=risk):
                self.assertIn(risk, content)
        self.assertIn("## Riesgos que impiden GO (NO-GO)", content)
        self.assertIn("Propietario (decisión no delegada)", content)
        self.assertIn("Go/no-go comercial", content)
        self.assertIn("Go-live / despliegue / producción", content)
        _status(content)
        premature_live = content.replace(
            "**Autorización go-live:** NO AUTORIZADO",
            "**Autorización go-live:** AUTORIZADO", 1,
        )
        with self.assertRaisesRegex(ValueError, "GO/UNKNOWN"):
            _status(premature_live)


if __name__ == "__main__":
    unittest.main()
