#!/usr/bin/env python3
"""Regression: fail closed when a GitHub Action in grindflow-ci.yml uses a moving ref."""

from __future__ import annotations

from pathlib import Path
import re
import unittest

WORKFLOW = Path(__file__).resolve().parents[1] / ".github/workflows/grindflow-ci.yml"
USES_LINE = re.compile(r"(?m)^\s*(?:-\s*)?uses:\s*(?P<action>[^\s#]+)")
PINNED = re.compile(r"^(?:[A-Za-z0-9_.-]+/)+[A-Za-z0-9_.-]+@[0-9a-f]{40}$")


def check_pinned_actions(workflow: str) -> int:
    """Validate all external actions, rejecting tags, branches, and dynamic refs."""
    matches = list(USES_LINE.finditer(workflow))
    if not matches:
        raise ValueError("Missing uses declarations; cannot accredit SHA pinning")
    checked = 0
    for match in matches:
        action = match.group("action")
        if action.startswith("./"):
            continue  # local repository action; no remote ref to pin
        if not PINNED.fullmatch(action):
            line = workflow.count("\n", 0, match.start()) + 1
            raise ValueError(f"Unpinned or malformed external action at line {line}: {action}")
        checked += 1
    if checked == 0:
        raise ValueError("No external actions; suspicious empty coverage")
    return checked


class GrindflowCIActionPinsTests(unittest.TestCase):
    """AC-01 and AC-02: pinning is mandatory and regressions are observable."""

    def test_actions_have_exact_sha(self) -> None:
        source = WORKFLOW.read_text(encoding="utf-8")
        self.assertGreater(check_pinned_actions(source), 0)

    def test_unpinned_action_fails_closed(self) -> None:
        source = WORKFLOW.read_text(encoding="utf-8")
        examples = (
            ("actions/checkout", "fbc6f3992d24b796d5a048ff273f7fcc4a7b6c09", "v5"),
            ("actions/cache", "caa296126883cff596d87d8935842f9db880ef25", "v5"),
            ("actions/setup-node", "a0853c24544627f65ddf259abe73b1d18a591444", "v5"),
            ("actions/upload-artifact", "b7c566a772e6b6bfb58ed0dc250532a479d7789f", "v6"),
            ("shivammathur/setup-php", "eb7c497e18156a6bbabfef1d3a82760b9eda3962", "v2"),
        )
        for action, sha, moving_tag in examples:
            with self.subTest(action=action):
                expected = f"{action}@{sha}"
                self.assertIn(expected, source)
                compromised = source.replace(expected, f"{action}@{moving_tag}", 1)
                with self.assertRaisesRegex(ValueError, "Unpinned"):
                    check_pinned_actions(compromised)
        with self.assertRaisesRegex(ValueError, "Unpinned"):
            check_pinned_actions(source.replace(
                "actions/cache@caa296126883cff596d87d8935842f9db880ef25",
                "actions/cache@${{ github.ref }}", 1,
            ))


def load_tests(loader: unittest.TestLoader, suite: unittest.TestSuite,
               pattern: str | None) -> unittest.TestSuite:
    """GF957: ejecutar todas las regresiones de verificación en fast.

    El workflow ya ejecuta este módulo con unittest. Este puente mantiene
    la validación de pines y evita modificar el workflow reclamado por otros PR.
    No implica acreditar producción ni cumplimiento de requisitos integrales.
    """
    target = Path(__file__).with_name("test_verificacion_grindflow_01.py")
    if not target.is_file():
        raise FileNotFoundError("GF957: falta test_verificacion_grindflow_01.py")
    extra = loader.discover(start_dir=str(target.parent), pattern=target.name)
    if extra.countTestCases() < 8:
        raise AssertionError("GF957: suite incompleta en fast")
    suite.addTests(extra)

    # GF975: conectar los cuatro escenarios GF348 al mismo fast canónico,
    # sin editar el workflow compartido por las ramas de migración/Dependabot.
    gf348 = Path(__file__).with_name("test_team_destinations_authorization_contract.py")
    if not gf348.is_file():
        raise FileNotFoundError("GF348: falta test_team_destinations_authorization_contract.py")
    auth = loader.discover(start_dir=str(gf348.parent), pattern=gf348.name)
    if auth.countTestCases() < 4:
        raise AssertionError("GF348: suite incompleta en fast")
    suite.addTests(auth)
    return suite


if __name__ == "__main__":
    unittest.main()
