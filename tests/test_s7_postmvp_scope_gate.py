"""#644: contrato S7 post-MVP; solo evalúa hechos sintéticos, nunca activa S7."""
from __future__ import annotations

import copy
import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TRANSITION = ROOT / "docs" / "STACK-TRANSITION-SYMFONY.md"
DECISIONS = ROOT / "decisiones.yml"
S7 = {"additional_integrations", "advanced_multiteam", "native_mobile", "aws_scale"}


def evaluate_s7(candidate: dict) -> tuple[str, tuple[str, ...]]:
    """Modelo *solo de prueba*: determina si un tramo merece revisión futura.

    Ningún resultado autoriza implementar, conectar proveedores o publicar.
    No utiliza red, procesos, cuentas o secretos; el input es desechable.
    """
    if not isinstance(candidate, dict) or not isinstance(candidate.get("scope"), str) or candidate["scope"] not in S7:
        return "BLOCKED", ("unknown_scope",)
    blocked = []
    decision = candidate.get("product_decision")
    if (not isinstance(decision, dict)
            or decision.get("status") != "APPROVED"
            or decision.get("owner") != "pl0n3r"
            or decision.get("issue") != 358
            or not re.fullmatch(r"GF-S7-[0-9]{3}", str(decision.get("id", "")))):
        blocked.append("owner_product_decision")
    if candidate.get("mvp_ready") is not True:
        blocked.append("mvp_readiness")
    evidence = candidate.get("usage_evidence")
    if (not isinstance(evidence, dict)
            or evidence.get("verified") is not True
            or evidence.get("source") not in {"measured_usage", "pilot_observation"}
            or type(evidence.get("sample_size")) is not int
            or evidence["sample_size"] < 1):
        blocked.append("measured_usage")
    costs = candidate.get("economics")
    if (not isinstance(costs, dict) or costs.get("verified") is not True
            or costs.get("source") != "measured_costs"
            or costs.get("unit_economics_approved") is not True):
        blocked.append("unit_economics")
    if candidate["scope"] == "additional_integrations":
        apis = candidate.get("provider_api")
        if (not isinstance(apis, dict) or apis.get("permissions_verified") is not True
                or apis.get("provider_reviewed") is not True):
            blocked.append("api_permissions")
    if candidate["scope"] == "native_mobile" and candidate.get("web_mobile_mvp_retained") is not True:
        blocked.append("preserve_web_mvp")
    if candidate.get("deploy_authorized") is True or candidate.get("new_provider_active") is True:
        blocked.append("runtime_activation_out_of_scope")
    if blocked:
        return "BLOCKED", tuple(blocked)
    return "REVIEW_ONLY", ()


def synthetic_candidate(scope="additional_integrations") -> dict:
    return {
        "scope": scope,
        "product_decision": {"status": "APPROVED", "owner": "pl0n3r", "issue": 358,
                             "id": "GF-S7-001"},
        "mvp_ready": True,
        "usage_evidence": {"verified": True, "source": "measured_usage", "sample_size": 10},
        "economics": {"verified": True, "source": "measured_costs",
                      "unit_economics_approved": True},
        "provider_api": {"permissions_verified": True, "provider_reviewed": True},
        "web_mobile_mvp_retained": True,
        "deploy_authorized": False,
        "new_provider_active": False,
    }


class PostMvpScopeGateTests(unittest.TestCase):
    def test_future_scope_requires_explicit_product_decision(self):
        # La aprobación es sintética; incluso ella solo produce REVIEW_ONLY.
        for scope in sorted(S7):
            with self.subTest(scope=scope):
                fixture = synthetic_candidate(scope)
                self.assertEqual(("REVIEW_ONLY", ()), evaluate_s7(fixture))
                for invalid_decision in (None, {}, {"status": "PLANNED"},
                                         {"status": "APPROVED", "owner": "other", "issue": 358,
                                          "id": "GF-S7-001"},
                                         {"status": "APPROVED", "owner": "pl0n3r", "issue": 2,
                                          "id": "GF-S7-001"},
                                         {"status": "APPROVED", "owner": "pl0n3r", "issue": 358,
                                          "id": "roadmap"}):
                    bad = copy.deepcopy(fixture)
                    bad["product_decision"] = invalid_decision
                    self.assertIn("owner_product_decision", evaluate_s7(bad)[1])
        # Un issue roadmap, una prueba/archivo presente o CI verde nunca es un voto.
        self.assertEqual("BLOCKED", evaluate_s7({"scope": "aws_scale", "issue": 358,
                                                   "ci": "success", "file_exists": True})[0])

    def test_missing_independent_evidence_stays_blocked(self):
        baseline = synthetic_candidate()
        cases = (
            ("mvp_ready", False, "mvp_readiness"),
            ("usage_evidence", {"verified": True, "source": "synthetic_fixture", "sample_size": 10}, "measured_usage"),
            ("usage_evidence", {"verified": True, "source": "measured_usage", "sample_size": 0}, "measured_usage"),
            ("economics", {"verified": True, "source": "measured_costs",
                            "unit_economics_approved": False}, "unit_economics"),
            ("provider_api", {"permissions_verified": True, "provider_reviewed": False}, "api_permissions"),
            ("deploy_authorized", True, "runtime_activation_out_of_scope"),
            ("new_provider_active", True, "runtime_activation_out_of_scope"),
        )
        for key, value, reason in cases:
            with self.subTest(key=key, value=value):
                fact = copy.deepcopy(baseline)
                fact[key] = value
                self.assertIn(reason, evaluate_s7(fact)[1])
        native = synthetic_candidate("native_mobile")
        native["web_mobile_mvp_retained"] = False
        self.assertIn("preserve_web_mvp", evaluate_s7(native)[1])
        for unexpected in (None, {}, {"scope": "aws"}, {"scope": "__unknown__"}, {"scope": []}):
            self.assertEqual("BLOCKED", evaluate_s7(unexpected)[0])

    def test_synthetic_evidence_never_authorizes_runtime(self):
        # Una evaluación revisable no es un permiso para activar ninguna fase S7.
        for scope in sorted(S7):
            with self.subTest(scope=scope):
                fixture = synthetic_candidate(scope)
                self.assertEqual("REVIEW_ONLY", evaluate_s7(fixture)[0])
                for attempted_activation in ("deploy_authorized", "new_provider_active"):
                    altered = copy.deepcopy(fixture)
                    altered[attempted_activation] = True
                    result, reasons = evaluate_s7(altered)
                    self.assertEqual("BLOCKED", result)
                    self.assertIn("runtime_activation_out_of_scope", reasons)

    def test_roadmap_policy_stays_explicitly_deferred(self):
        text = TRANSITION.read_text(encoding="utf-8")
        rows = [line for line in text.splitlines() if line.startswith("| **S7 · Escala** |")]
        self.assertEqual(1, len(rows), "S7 debe tener una sola fila normativa")
        columns = [part.strip() for part in rows[0].strip().strip("|").split("|")]
        self.assertEqual(3, len(columns))
        feature, acceptance = columns[1:]
        for marker in ("adicionales", "móvil nativa", "solo si justificadas"):
            self.assertIn(marker, feature)
        for marker in ("APIs", "proveedor revisados", "costos medidos", "permisos", "QA", "no bloquear S0–S5"):
            self.assertIn(marker, acceptance)
        policy = json.loads(DECISIONS.read_text(encoding="utf-8"))
        self.assertEqual(1, policy["version"])
        self.assertTrue(all(item["status"] == "active" for item in policy["decisions"]))
        self.assertFalse(any(item.get("id", "").startswith("GF-S7-") for item in policy["decisions"]),
                         "Un nuevo voto S7 no puede inferirse de decisiones técnicas generales")
        # Esta suite NO comprueba un voto GitHub real ni concede autorización.


if __name__ == "__main__":
    unittest.main()
