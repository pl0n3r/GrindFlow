#!/usr/bin/env python3
"""Valida reglas de gobierno sin mutar GitHub, versiones ni documentación."""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from urllib.parse import unquote

ROOT = Path(__file__).resolve().parents[1]
PR_TITLE = re.compile(r"^.+ \(V ([0-9]+\.[0-9]+\.[0-9]+)\)$")
VERSION = re.compile(r"'number'\s*=>\s*'([0-9]+\.[0-9]+\.[0-9]+)'")
LINK = re.compile(r"\[[^\]]+\]\(([^)]+)\)")
TEMPLATE_TITLE = re.compile(r'^title:\s*"[^"]+\(V X\.Y\.Z\)"\s*$', re.M)
LABEL_TYPE_IMPROVEMENT = "tipo: mejora"
LABEL_PRIORITY_MEDIUM = "prioridad: media"
LABEL_REVIEW_STATE = "estado: en revisión"
DEPENDABOT_REQUIRED_LABELS = {
    LABEL_TYPE_IMPROVEMENT,
    LABEL_PRIORITY_MEDIUM,
    LABEL_REVIEW_STATE,
}


def dependabot_errors(content: str, label_names: set[str]) -> list[str]:
    """Valida el contrato mínimo de actualizaciones automáticas."""
    failures: list[str] = []
    missing_labels = sorted(DEPENDABOT_REQUIRED_LABELS - label_names)
    if missing_labels:
        failures.append(
            "Dependabot referencia labels no declarados en labels.json: "
            + ", ".join(missing_labels)
        )

    composer = re.search(
        r'- package-ecosystem:\s*["\']composer["\']',
        content,
    )
    if composer is None:
        failures.append("dependabot.yml debe configurar Composer.")
        return failures

    body_start = composer.end()
    next_ecosystem = re.search(r"\n\s*- package-ecosystem:", content[body_start:])
    body_end = (
        body_start + next_ecosystem.start()
        if next_ecosystem is not None
        else len(content)
    )
    body = content[body_start:body_end]
    if "directories:" not in body:
        failures.append("Dependabot Composer debe usar directories para múltiples manifests.")
    for required in ('"/"', '"/symfony"'):
        if not re.search(rf"^\s*-\s*{re.escape(required)}\s*$", body, flags=re.M):
            failures.append(f"Dependabot Composer debe incluir {required}.")
    return failures


REQUIRED = (
    "AGENTS.md",
    "README.md",
    "ROADMAP.md",
    "GLOSARIO.md",
    "docs/GOVERNANCE.md",
    "docs/GRINDFLOW-SPEC.md",
    "docs/REQUIREMENTS.md",
    ".github/pull_request_template.md",
    ".github/labels.json",
    ".github/ISSUE_TEMPLATE/config.yml",
    ".github/ISSUE_TEMPLATE/error.yml",
    ".github/ISSUE_TEMPLATE/mejora.yml",
    ".github/ISSUE_TEMPLATE/tarea.yml",
)


def product_version(content: str) -> str:
    found = VERSION.findall(content)
    if len(found) != 1:
        raise ValueError("Se requiere exactamente una versión de producto en config/version.php.")
    return found[0]


def validate_title(title: str, version: str) -> None:
    match = PR_TITLE.fullmatch(title)
    if match is None:
        raise ValueError("El título del PR debe terminar exactamente con (V X.Y.Z).")
    if match.group(1) != version:
        raise ValueError(
            f"El título del PR indica V {match.group(1)}, "
            f"pero config/version.php define V {version}."
        )


def relative_link_errors(path: Path, root: Path) -> list[str]:
    failures: list[str] = []
    for match in LINK.finditer(path.read_text(encoding="utf-8")):
        raw = match.group(1).strip().strip("<>")
        target = raw.split("#", 1)[0].split("?", 1)[0]
        if " " in target:
            target = target.split(" ", 1)[0]
        if not target or re.match(r"^(https?://|mailto:|data:)", target, re.I):
            continue
        target = unquote(target)
        resolved = root / target.lstrip("/") if target.startswith("/") else path.parent / target
        if not resolved.resolve().is_relative_to(root.resolve()) or not resolved.exists():
            failures.append(f"{path.relative_to(root)}: enlace local roto {raw!r}")
    return failures


def governance_errors(root: Path) -> list[str]:
    errors = [f"Falta {name}" for name in REQUIRED if not (root / name).is_file()]
    if errors:
        return errors

    try:
        product_version((root / "config/version.php").read_text(encoding="utf-8"))
    except (OSError, ValueError) as error:
        errors.append(str(error))

    try:
        labels = json.loads((root / ".github/labels.json").read_text(encoding="utf-8"))
        if not isinstance(labels, list):
            raise ValueError("labels.json debe contener una lista.")
        names = [label["name"] for label in labels]
        if len(names) != len(set(names)):
            errors.append("labels.json contiene nombres duplicados.")
        for label in labels:
            if not re.fullmatch(r"[0-9a-fA-F]{6}", label["color"]):
                errors.append(f"Color inválido en label: {label['name']}")
        dependabot = root / ".github/dependabot.yml"
        if dependabot.is_file():
            errors.extend(
                dependabot_errors(
                    dependabot.read_text(encoding="utf-8"),
                    set(names),
                )
            )
    except (ValueError, KeyError, TypeError) as error:
        errors.append(f"labels.json inválido: {error}")

    for path in sorted((root / ".github/ISSUE_TEMPLATE").glob("*.yml")):
        if path.name == "config.yml":
            continue
        if not TEMPLATE_TITLE.search(path.read_text(encoding="utf-8")):
            errors.append(f"{path.name}: título guía debe terminar con (V X.Y.Z).")

    for name in ("README.md", "ROADMAP.md", "GLOSARIO.md", "docs/GOVERNANCE.md"):
        errors.extend(relative_link_errors(root / name, root))

    roadmap = (root / "ROADMAP.md").read_text(encoding="utf-8")
    if "https://github.com/pl0n3r/GrindFlow/issues/2" not in roadmap:
        errors.append("ROADMAP.md debe apuntar al Issue #2.")
    if re.search(r"(?:/issues/88\b|\[(?:roadmap|issue)[^\]]*#88\])", roadmap, flags=re.I):
        errors.append("ROADMAP.md no debe apuntar al Issue #88 histórico como roadmap activo.")
    return errors


def self_test() -> None:
    version = "0.1.16"
    validate_title("infra: adopta prácticas de Condor (V 0.1.16)", version)
    for invalid in (
        "infra: sin versión", "infra (v0.1.16)",
        "infra (V 0.1.15)", "infra (V 0.1.16) nota",
        "infra (V 0.1.16)\nextra",
    ):
        try:
            validate_title(invalid, version)
        except ValueError:
            continue
        raise AssertionError(f"Título incorrecto aceptado: {invalid!r}")
    assert product_version("<?php return ['number' => '0.1.16'];") == version
    valid_dependabot = """updates:
  - package-ecosystem: "composer"
    directories:
      - "/"
      - "/symfony"
"""
    declared = set(DEPENDABOT_REQUIRED_LABELS)
    assert dependabot_errors(valid_dependabot, declared) == []
    assert dependabot_errors(valid_dependabot, {LABEL_TYPE_IMPROVEMENT})
    assert dependabot_errors(
        'updates:\n  - package-ecosystem: "composer"\n    directory: "/"\n',
        declared,
    )
    print("Contrato de gobierno: títulos, labels y Dependabot comprobados.")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--pr-title")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    try:
        if args.self_test:
            self_test()
            return 0
        problems = governance_errors(ROOT)
        if args.pr_title is not None:
            try:
                version = product_version(
                    (ROOT / "config/version.php").read_text(encoding="utf-8")
                )
                validate_title(args.pr_title, version)
            except ValueError as error:
                problems.append(str(error))
        if problems:
            for error in problems:
                print("GOBIERNO FALLIDO: " + error, file=sys.stderr)
            return 1
        print("Gobierno, documentos, labels y títulos validados.")
        return 0
    except (OSError, AssertionError) as error:
        print("GOBIERNO FALLIDO: " + str(error), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
