from __future__ import annotations

import unittest

from scripts.ci_retry import transient, validate


class CiRetryTests(unittest.TestCase):
    def test_retry_is_limited_to_external_signals(self) -> None:
        self.assertTrue(transient(1, "HTTP 503 unavailable"))
        self.assertTrue(transient(75, "temporary"))
        self.assertFalse(transient(1, "test assertion failed"))

    def test_bounds_are_enforced(self) -> None:
        validate(["echo", "ok"], 5, 30)
        with self.assertRaises(ValueError):
            validate([], 1, 1)
        with self.assertRaises(ValueError):
            validate(["echo"], 6, 1)
