"""Contrato del consumidor para la review CodeRabbit anclada al HEAD exacto."""
from __future__ import annotations
import json
import unittest
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
REVIEWER = "coderabbitai[bot]"

def substantive_review_satisfies(review: dict, *, required_bot: str, head_sha: str) -> bool:
    user = review.get("user")
    state = review.get("state")
    body = review.get("body")
    return (
        isinstance(user, dict)
        and user.get("type") == "Bot"
        and user.get("login") == required_bot
        and review.get("commit_id") == head_sha
        and state != "CHANGES_REQUESTED"
        and (state == "APPROVED" or (state == "COMMENTED" and isinstance(body, str) and bool(body.strip())))
    )

class CodeRabbitPolicyContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.policy = json.loads((ROOT / ".github/factory-policy.json").read_text(encoding="utf-8"))
        cls.workflow = (ROOT / ".github/workflows/politica.yml").read_text(encoding="utf-8")
        cls.docs = (ROOT / "docs/AGENT-OPERATIONS.md").read_text(encoding="utf-8")
        cls.governance = (ROOT / "docs/GOVERNANCE.md").read_text(encoding="utf-8")
        cls.decisions = json.loads((ROOT / "decisiones.yml").read_text(encoding="utf-8"))

    def test_factory_policy_requires_coderabbit(self) -> None:
        self.assertEqual(self.policy, {"version": 1, "required_review_bot": REVIEWER})

    def test_policy_caller_passes_same_required_bot(self) -> None:
        self.assertEqual(self.workflow.count("required_review_bot: coderabbitai[bot]"), 1)
        self.assertIn("pr_number: ${{ github.event.pull_request.number }}", self.workflow)
        self.assertIn("pl0n3r/factory/.github/workflows/politica.yml@v1", self.workflow)
        self.assertIn("pull_request_review:", self.workflow)
        self.assertIn("types: [submitted, edited, dismissed]", self.workflow)
        self.assertIn("pull-requests: read", self.workflow)
        self.assertNotIn("pull-requests: write", self.workflow)
        self.assertNotIn("secrets:", self.workflow)

    def test_previous_head_review_does_not_satisfy_current_head(self) -> None:
        review={"user":{"type":"Bot","login":REVIEWER},"state":"COMMENTED","body":"review completa","commit_id":"1"*40}
        self.assertFalse(substantive_review_satisfies(review, required_bot=REVIEWER, head_sha="2"*40))

    def test_exact_head_substantive_review_is_required(self) -> None:
        review={"user":{"type":"Bot","login":REVIEWER},"state":"COMMENTED","body":"review completa","commit_id":"2"*40}
        self.assertTrue(substantive_review_satisfies(review, required_bot=REVIEWER, head_sha="2"*40))
        requested={**review,"state":"CHANGES_REQUESTED","body":"hay cambios"}
        self.assertFalse(substantive_review_satisfies(requested, required_bot=REVIEWER, head_sha="2"*40))

    def test_rate_limit_or_status_without_review_does_not_satisfy(self) -> None:
        empty={"user":{"type":"Bot","login":REVIEWER},"state":"COMMENTED","body":"","commit_id":"2"*40}
        status={"status":"success","context":"CodeRabbit"}
        self.assertFalse(substantive_review_satisfies(empty, required_bot=REVIEWER, head_sha="2"*40))
        self.assertFalse(substantive_review_satisfies(status, required_bot=REVIEWER, head_sha="2"*40))

    def test_documented_rule_accepts_exact_head_review_or_terminal_coverage(self) -> None:
        for expected in (
            "review formal",
            "final_review_risk_coverage",
            "coderabbitai[bot]",
            "coveredCommitId",
            "HEAD exacto",
            "Política Factory v1",
            "hallazgos bloqueantes",
            "Si cambia el HEAD",
            "Full review finished",
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, self.docs)

    def test_documented_factory_959_fallback_is_scoped_to_construction_only(self) -> None:
        """Comprueba relaciones y mutaciones negativas dentro de la cláusula real."""
        self.assertEqual(self.docs.count("5. Si CodeRabbit"), 1)
        section = self.docs.split("5. Si CodeRabbit", 1)[1].split("6. No reinterpretar", 1)[0]
        normalized = " ".join(section.split())
        required = (
            "**Factory #959**, exclusiva de `phase=construccion`",
            "`datos.yml` de la BASE exacta",
            "requiere rate-limit exact-HEAD del bot",
            "fallo previo de `Política Factory v1`",
            "un **retry OWNER**",
            "todos los demás checks obligatorios terminales y verdes",
            "cero `CHANGES_REQUESTED`, hilos abiertos o **hallazgos bloqueantes**",
            "El check `Política Factory v1` debe terminar SUCCESS",
            "En `live`, fase desconocida o cualquier otra fase el fallback está prohibido",
        )

        def policy_is_scoped(value: str) -> bool:
            return all(fragment in value for fragment in required)

        self.assertTrue(policy_is_scoped(normalized))
        # Un test meramente lexical pasaría si se invirtiera la regla de live,
        # se quitara el reintento o se ampliara la excepción a otra fase.
        for changed in (
            normalized.replace("exclusiva de `phase=construccion`", "aplicable en `live`", 1),
            normalized.replace("un **retry OWNER**", "sin reintento", 1),
            normalized.replace("el fallback está prohibido", "el fallback está permitido", 1),
            normalized.replace("cero `CHANGES_REQUESTED`, hilos abiertos", "se toleran hilos abiertos", 1),
        ):
            with self.subTest(changed=changed[-100:]):
                self.assertFalse(policy_is_scoped(changed))

        # La norma local histórica ya no debe exigir un permiso owner por PR
        # adicional al fallback global posterior aprobado en Factory #959.
        self.assertIn("Factory #959 no exige una autorización individual por PR", self.governance)
        self.assertIn("En `live`, fase desconocida o cualquier fase distinta", self.governance)
        self.assertIn("todos los demás", self.governance)
        self.assertNotIn("Únicamente una autorización expresa del propietario para una PR concreta", self.governance)

    def test_owner_decision_b_is_recorded_without_changing_round_limit(self) -> None:
        self.assertEqual(self.decisions["review_round_limit"], 3)
        decision = next(item for item in self.decisions["decisions"] if item["id"] == "D-062")
        self.assertEqual(decision["status"], "active")
        for expected in (
            "#219",
            "A por B",
            "final_review_risk_coverage",
            "coderabbitai[bot]",
            "coveredCommitId",
            "HEAD exacto",
            "Politica Factory v1",
            "review_round_limit=3",
            "no existen hallazgos bloqueantes",
            "si cambia el HEAD la cobertura debe renovarse",
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, decision["text"])

    def test_documented_rule_and_machine_policy_stay_aligned(self) -> None:
        self.assertEqual(self.policy, {"version": 1, "required_review_bot": REVIEWER})
        self.assertEqual(self.workflow.count("required_review_bot: coderabbitai[bot]"), 1)
        for expected in (
            ".github/factory-policy.json",
            "coderabbitai[bot]",
            "HEAD exacto",
            "review formal",
            "final_review_risk_coverage",
            "CHANGES_REQUESTED",
            "rate-limit",
            "Política Factory v1",
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, self.docs)

if __name__ == "__main__":
    unittest.main()
