"""Offline retry contracts for GitHub's secondary rate limits."""

import importlib.util
import io
import time
import unittest
import urllib.error
from pathlib import Path
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "sonar-pr-comment.py"
spec = importlib.util.spec_from_file_location("sonar_pr_comment", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
URL = "https://api.github.com/repos/pl0n3r/GrindFlow/issues"


def failure(code, headers=None):
    return urllib.error.HTTPError(
        URL, code, "rate limit", headers or {}, io.BytesIO(b'{"message":"limited"}'),
    )


class GitHubRateLimitTest(unittest.TestCase):
    def request(self, *, method="GET", source="GitHub"):
        return module.request_json(URL, method=method, source=source)

    def test_429_retries_get_with_bounded_exponential_backoff(self):
        with patch.object(module.urllib.request, "urlopen", side_effect=[
            failure(429), io.BytesIO(b'{"ok": true}'),
        ]) as urlopen, patch.object(module.time, "sleep") as sleep:
            self.assertEqual(self.request(), {"ok": True})
            self.assertEqual(urlopen.call_count, 2)
            sleep.assert_called_once_with(2.0)

    def test_retry_after_takes_precedence_over_default_backoff(self):
        with patch.object(module.urllib.request, "urlopen", side_effect=[
            failure(429, {"Retry-After": "9"}), io.BytesIO(b"{}"),
        ]), patch.object(module.time, "sleep") as sleep:
            self.assertEqual(self.request(), {})
            sleep.assert_called_once_with(9.0)

    def test_403_without_rate_limit_evidence_does_not_retry(self):
        with patch.object(module.urllib.request, "urlopen", side_effect=failure(403)) as urlopen:
            with self.assertRaises(module.ApiError):
                self.request()
            self.assertEqual(urlopen.call_count, 1)

    def test_403_with_retry_after_does_retry(self):
        with patch.object(module.urllib.request, "urlopen", side_effect=[
            failure(403, {"Retry-After": "3"}), io.BytesIO(b"{}"),
        ]), patch.object(module.time, "sleep") as sleep:
            self.assertEqual(self.request(), {})
            sleep.assert_called_once_with(3.0)

    def test_reset_epoch_is_honored_when_remaining_zero(self):
        with patch.object(module.time, "time", return_value=1000), patch.object(
            module.urllib.request, "urlopen", side_effect=[
                failure(403, {"X-RateLimit-Remaining": "0", "X-RateLimit-Reset": "1012"}),
                io.BytesIO(b"{}"),
            ],
        ), patch.object(module.time, "sleep") as sleep:
            self.assertEqual(self.request(), {})
            sleep.assert_called_once_with(12.0)

    def test_long_retry_after_fails_closed_instead_of_retrying_early(self):
        with patch.object(module.urllib.request, "urlopen", side_effect=
                   failure(429, {"Retry-After": "60"})) as urlopen, patch.object(
                       module.time, "sleep",
                   ) as sleep:
            with self.assertRaises(module.ApiError):
                self.request()
            self.assertEqual(urlopen.call_count, 1)
            sleep.assert_not_called()

    def test_non_idempotent_post_never_retries(self):
        with patch.object(module.urllib.request, "urlopen", side_effect=failure(429)) as urlopen:
            with self.assertRaises(module.ApiError):
                self.request(method="POST")
            self.assertEqual(urlopen.call_count, 1)

    def test_sonar_requests_do_not_change_retry_policy(self):
        with patch.object(module.urllib.request, "urlopen", side_effect=failure(429)) as urlopen:
            with self.assertRaises(module.ApiError):
                self.request(source="Sonar")
            self.assertEqual(urlopen.call_count, 1)

    def test_maximum_attempts_are_three_not_unbounded(self):
        with patch.object(module.urllib.request, "urlopen", side_effect=[
            failure(429), failure(429), failure(429),
        ]) as urlopen, patch.object(module.time, "sleep") as sleep:
            with self.assertRaises(module.ApiError):
                self.request()
            self.assertEqual(urlopen.call_count, 3)
            self.assertEqual([call.args[0] for call in sleep.call_args_list], [2.0, 4.0])

    def test_patch_is_idempotent_and_may_retry(self):
        with patch.object(module.urllib.request, "urlopen", side_effect=[
            failure(429), io.BytesIO(b"{}"),
        ]) as urlopen, patch.object(module.time, "sleep"):
            self.assertEqual(self.request(method="PATCH"), {})
            self.assertEqual(urlopen.call_count, 2)


if __name__ == "__main__":
    unittest.main()
