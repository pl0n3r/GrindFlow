#!/usr/bin/env python3
"""GrindFlow #714: contraste offline de SPEC:008..SPEC:012.

Una declaración de producto, un test local o un CI verde no prueban
piloto realizado, condiciones comerciales aprobadas ni producción.
"""
from __future__ import annotations

import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/GRINDFLOW-SPEC.md"
TRANSITION = "docs/STACK-TRANSITION-SYMFONY.md"
SYMFONY = "symfony/composer.json"
FRONTEND = "symfony/package.json"
LARAVEL = "composer.json"
VAULT = "symfony/src/Http/Controller/VaultController.php"
PHOTO_TEST = "symfony/tests/php/VaultTest.php"
VIDEO_TEST = "symfony/tests/php/VaultVideoTest.php"
IDS = tuple(f"SPEC:{i:03d}" for i in range(8, 13))
UNKNOWN = "UNKNOWN"
LOCAL = "CONTRATO_LOCAL"
PATHS = (MATRIX, SPEC, TRANSITION, SYMFONY, FRONTEND, LARAVEL,
         VAULT, PHOTO_TEST, VIDEO_TEST)

SECTIONS = {
    "SPEC:008": "#### 1A.4.2. Piloto inicial",
    "SPEC:009": "#### 1A.4.3. Prueba gratuita comercial",
    "SPEC:010": "## 2. Arquitectura objetivo confirmada el 20/09/2026",
    "SPEC:011": "## 3. Arquitectura y límites funcionales",
    "SPEC:012": "### S2 · Biblioteca multimedia privada",
}

# Cada cláusula tiene un predicado propio. Los tokens son invariantes
# documentales; nunca equivalen por sí mismos a evidencia productiva.
CLAUSE_CONTRACTS = {
    "SPEC:008": ("piloto gratuito con cinco creadoras", "tiempo ahorrado",
                 "costos técnicos", "tráfico medible", "no evidencia"),
    "SPEC:009": ("30 días", "dos redes sociales", "Decisión pendiente",
                 "parámetros propuestos", "no un paquete"),
    "SPEC:010": ("Symfony 7.4 LTS", "Doctrine ORM/DBAL",
                 "React 19", "Twig/Symfony SSR", "objetivo ≠ implementado"),
    "SPEC:011": ("Symfony monolítica modular", "conserva",
                 "aplicación Laravel", "propietario único de escritura",
                 "No modificar/borrar tablas Laravel"),
    "SPEC:012": ("originales privados del tenant", "JPEG/PNG/WebP",
                 "MP4/WebM", "8 MiB", "SHA-256/deduplicación",
                 "sesión, organización e integridad", "sin autoplay"),
}


def parse_matrix(raw: str) -> dict[str, tuple[str, ...]]:
    """Solo filas únicas y completas; no promover una fila ambigua."""
    result: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        if not any(line.startswith(f"| {identifier} |") for identifier in IDS):
            continue
        cells = tuple(part.strip() for part in line.strip("|").split("|"))
        if len(cells) != 8 or cells[0] in result:
            raise ValueError("UNKNOWN: fila SPEC ambigua o truncada")
        result[cells[0]] = cells
    return result


def section(source: str, title: str) -> str | None:
    """Exigir encabezado único, nunca casar una mención en otro apartado."""
    found = list(re.finditer(r"(?m)^" + re.escape(title) + r"[ \t]*$", source))
    if len(found) != 1:
        return None
    remainder = source[found[0].end():]
    boundary = re.search(r"(?m)^#{2,4} ", remainder)
    return remainder[:boundary.start()] if boundary else remainder


def inspect(clause: str, rows: dict[str, tuple[str, ...]],
            sources: dict[str, str]) -> dict[str, str]:
    """La prueba sólo puede acreditar un contrato local, jamás el live."""
    result = {"code": UNKNOWN, "production": UNKNOWN,
              "commercial": UNKNOWN, "pilot": UNKNOWN}
    row = rows.get(clause)
    if (clause not in SECTIONS or row is None or len(row) != 8 or
            row[2] != "NO_VERIFICABLE" or
            row[5:8] != ("NO_ACREDITADO",) * 3 or
            "GRINDFLOW-SPEC.md" not in row[3]):
        return result
    spec_section = section(sources.get(SPEC, ""), SECTIONS[clause])
    if not spec_section or not all(
        token.casefold() in spec_section.casefold()
        for token in CLAUSE_CONTRACTS[clause]
    ):
        return result

    # Piloto y prueba comercial: términos propuestos NO prueban ejecución.
    if clause in {"SPEC:008", "SPEC:009"}:
        return result

    try:
        composer = json.loads(sources[SYMFONY])
        frontend = json.loads(sources[FRONTEND])
        laravel = json.loads(sources[LARAVEL])
    except (KeyError, TypeError, ValueError):
        return result
    dependencies = composer.get("require", {})
    front = frontend.get("dependencies", {})
    development = frontend.get("devDependencies", {})
    if not (
        dependencies.get("symfony/framework-bundle", "").startswith("7.4") and
        "doctrine/orm" in dependencies and
        "doctrine/dbal" in dependencies and
        front.get("react", "").startswith("^19") and
        "typescript" in development and "vite" in development
    ):
        return result
    if clause == "SPEC:010":
        # PHP 8.5 es objetivo, no se atribuye al hosting observado.
        result["code"] = LOCAL
    elif clause == "SPEC:011":
        transition = sources.get(TRANSITION, "")
        if (
            "laravel/framework" in laravel.get("require", {}) and
            "propietario de escritura por módulo" in transition and
            "No modificar/borrar tablas Laravel" in spec_section
        ):
            result["code"] = LOCAL
    elif clause == "SPEC:012":
        vault = sources.get(VAULT, "")
        photo = sources.get(PHOTO_TEST, "")
        video = sources.get(VIDEO_TEST, "")
        if (
            "MAX_BYTES = 8 * 1024 * 1024" in vault and
            all(mime in vault for mime in
                ("image/jpeg", "image/png", "image/webp", "video/mp4", "video/webm")) and
            "hash_file('sha256'" in vault and
            "organization_id = :organization" in vault and
            "testPrivatePhotoUploadListDownloadAndCrossTenantGuards" in photo and
            "testQuickUploadAcceptsPrivateMp4AndWebmWithTenantSafePreview" in video and
            "self::assertResponseStatusCodeSame(404)" in video
        ):
            result["code"] = LOCAL
    return result


class BrechasGrindflow07Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.sources = {p: (ROOT / p).read_text(encoding="utf-8") for p in PATHS}
        cls.rows = parse_matrix(cls.sources[MATRIX])

    def test_contrato_principal(self) -> None:
        """AC-01: cinco filas, cada una con predicado o UNKNOWN explícito."""
        self.assertEqual(set(self.rows), set(IDS))
        self.assertEqual(set(CLAUSE_CONTRACTS), set(IDS))
        for identifier in IDS:
            with self.subTest(identifier=identifier):
                text = section(self.sources[SPEC], SECTIONS[identifier])
                self.assertIsNotNone(text, identifier)
                for token in CLAUSE_CONTRACTS[identifier]:
                    self.assertIn(token.casefold(), text.casefold(), identifier)
                expected = UNKNOWN if identifier in {"SPEC:008", "SPEC:009"} else LOCAL
                self.assertEqual(expected, inspect(identifier, self.rows, self.sources)["code"])

    def test_falla_cerrado(self) -> None:
        """AC-02: ausencia, duplicados, contratos rotos y datos ambiguos."""
        for identifier in IDS:
            with self.subTest(identifier=identifier):
                missing = dict(self.rows)
                del missing[identifier]
                self.assertEqual(UNKNOWN, inspect(identifier, missing, self.sources)["code"])
                missing_spec = {**self.sources, SPEC: ""}
                self.assertEqual(UNKNOWN, inspect(identifier, self.rows, missing_spec)["code"])
                invalid = dict(self.rows)
                row = list(invalid[identifier])
                row[2] = "CUBIERTO"
                invalid[identifier] = tuple(row)
                self.assertEqual(UNKNOWN, inspect(identifier, invalid, self.sources)["code"])
                sectionless = {**self.sources, SPEC: self.sources[SPEC].replace(
                    SECTIONS[identifier], "## encabezado no autorizado", 1)}
                self.assertEqual(UNKNOWN, inspect(identifier, self.rows, sectionless)["code"])
        first = next(line for line in self.sources[MATRIX].splitlines()
                     if line.startswith("| SPEC:008 |"))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            parse_matrix(self.sources[MATRIX] + "\n" + first)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            parse_matrix("| SPEC:008 | incompleta |")
        for identifier in ("SPEC:010", "SPEC:011", "SPEC:012"):
            self.assertEqual(UNKNOWN, inspect(
                identifier, self.rows, {**self.sources, SYMFONY: "{}"})["code"])
        self.assertEqual(UNKNOWN, inspect(
            "SPEC:012", self.rows, {**self.sources, VIDEO_TEST: ""})["code"])
        self.assertEqual(UNKNOWN, inspect(
            "SPEC:011", self.rows, {**self.sources, TRANSITION: ""})["code"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: solo leer archivos versionados, sin red ni PII/secretos."""
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|subprocess|urllib)\b")
        self.assertNotRegex(own, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(own, r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}")
        self.assertEqual(set(PATHS), set(self.sources))
        for path in PATHS:
            self.assertFalse(Path(path).is_absolute())
            self.assertTrue((ROOT / path).is_file())
        # Nunca ejecutar smoke, PHP productivo ni un enrolamiento comercial.

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: calidad exact-HEAD solo en CI; negocio/live no acreditados."""
        for identifier in IDS:
            with self.subTest(identifier=identifier):
                row = self.rows[identifier]
                self.assertEqual("NO_VERIFICABLE", row[2])
                self.assertEqual(("NO_ACREDITADO",) * 3, row[5:8])
                result = inspect(identifier, self.rows, self.sources)
                for field in ("production", "commercial", "pilot"):
                    self.assertEqual(UNKNOWN, result[field])
                if identifier in ("SPEC:008", "SPEC:009"):
                    self.assertEqual(UNKNOWN, result["code"])
        self.assertIsNone(section("", SECTIONS["SPEC:008"]))
        self.assertIsNone(section(
            self.sources[SPEC] + "\n" + SECTIONS["SPEC:008"] + "\n",
            SECTIONS["SPEC:008"],
        ))
        # Una fila anotada CUBIERTO no es prueba de deploy/autoridad.
        fake = dict(self.rows)
        row = list(fake["SPEC:012"])
        row[2] = "CUBIERTO"
        fake["SPEC:012"] = tuple(row)
        self.assertEqual(UNKNOWN, inspect("SPEC:012", fake, self.sources)["production"])


if __name__ == "__main__":
    unittest.main()
