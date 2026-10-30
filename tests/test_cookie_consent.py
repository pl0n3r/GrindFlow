"""Contrato offline de banner de cookies #782; sin redes, sesiones ni datos reales."""
from __future__ import annotations

import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TWIG = ROOT / "symfony/templates/base.html.twig"
BLADE = ROOT / "resources/views/components/release-footer.blade.php"
JS = ROOT / "public/js/cookie-consent.js"
CSS = ROOT / "public/css/cookie-consent.css"


class CookieConsentTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.twig = TWIG.read_text(encoding="utf-8")
        cls.blade = BLADE.read_text(encoding="utf-8")
        cls.js = JS.read_text(encoding="utf-8")
        cls.css = CSS.read_text(encoding="utf-8")

    def test_banner_contract(self) -> None:
        """AC-01: ambas interfaces ofrecen consentimiento reversible explícito."""
        for template in (self.twig, self.blade):
            with self.subTest(template="twig" if template is self.twig else "blade"):
                self.assertIn('data-gf-cookie-banner', template)
                self.assertIn('role="region"', template)
                self.assertIn('aria-labelledby="gf-cookie-consent-title"', template)
                self.assertIn('data-gf-cookie-open', template)
                self.assertIn('data-gf-cookie-legal-pending', template)
                self.assertIn('pendiente', template)
                buttons = re.findall(
                    r'<button[^>]*class="([^"]+)"[^>]*data-gf-cookie-action="(accept|reject)"',
                    template,
                )
                self.assertEqual(
                    buttons,
                    [
                        ("gf-cookie-consent__action", "accept"),
                        ("gf-cookie-consent__action", "reject"),
                    ],
                )
                self.assertIn(">Aceptar</button>", template)
                self.assertIn(">Rechazar</button>", template)
                self.assertNotRegex(template, r"(?i)<input[^>]+(?:checked|type=['\"]checkbox)")
                self.assertIn('data-gf-cookie-action="close"', template)
                self.assertIn('aria-label="Cerrar aviso y rechazar"', template)
                if template is self.twig:
                    inline_css = re.search(r"<style data-gf-cookie-inline-css>(.*?)</style>", template, re.S)
                    inline_js = re.search(r"<script data-gf-cookie-inline-js>(.*?)</script>", template, re.S)
                    self.assertIsNotNone(inline_css)
                    self.assertIsNotNone(inline_js)
                    self.assertEqual(inline_css.group(1).strip(), self.css.strip())
                    self.assertEqual(inline_js.group(1).strip(), self.js.strip())
                else:
                    self.assertRegex(template, r"cookie-consent\.css")
                    self.assertRegex(template, r"cookie-consent\.js")
                self.assertNotRegex(template, r'(?i)<script[^>]+src=["\']https?://')

        # AC-01 covers both markup and behavioral privacy regressions.
        self.test_no_optional_scripts_or_consent_by_default()
        self.test_cookie_has_expiry_and_safe_flags()
        self.test_a11y_and_reduced_motion()
        if shutil.which("node"):
            self.test_runtime_reject_default_accept_reopen_escape()

    def test_no_optional_scripts_or_consent_by_default(self) -> None:
        self.assertIn('storedChoice() || "rejected"', self.js)
        self.assertIn('choice === "accepted"', self.js)
        self.assertIn('const choices = ["accepted", "rejected"]', self.js)
        self.assertNotIn("createElement(", self.js)
        self.assertNotRegex(self.js, r"https?://|gtag\(|analytics\(|pixel\(")
        for template in (self.twig, self.blade):
            self.assertNotRegex(template, r'(?i)(gtag|analytics|tracking|facebook\.net)')
        self.assertIn('data-gf-cookie-action="reject"', self.twig)

    def test_cookie_has_expiry_and_safe_flags(self) -> None:
        for value in ("Max-Age=", "Path=/", "SameSite=Lax", "Secure", "https:"):
            self.assertIn(value, self.js)
        self.assertIn("60 * 60 * 24 * 180", self.js)
        self.assertIn("grindflow:cookie-consent", self.js)
        self.assertIn("openPreferences", self.js)

    def test_a11y_and_reduced_motion(self) -> None:
        self.assertIn("min-height: 44px", self.css)
        self.assertIn("min-width: 44px", self.css)
        self.assertIn(":focus-visible", self.css)
        self.assertIn("@media (prefers-reduced-motion: reduce)", self.css)
        self.assertIn("transition: none !important", self.css)
        self.assertIn("animation: none !important", self.css)
        self.assertIn('event.key === "Escape"', self.js)
        self.assertIn("reject.focus()", self.js)

    def test_runtime_reject_default_accept_reopen_escape(self) -> None:
        """A real JS VM verifies no consent, explicit choice and revocation."""
        node = shutil.which("node")
        if node is None:
            self.skipTest("Node.js no está instalado; las aserciones estáticas siguen activas")
        runner = r"""
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const code = fs.readFileSync(process.argv[1], 'utf8');
let cookie = '';
let events = [];
const bannerHooks = {};
const linkHooks = {};
let focused = false;
const reject = {focus() { focused = true; }};
const banner = {
  hidden: true,
  addEventListener(name, fn) { bannerHooks[name] = fn; }
};
const link = {addEventListener(name, fn) { linkHooks[name] = fn; }};
const document = {
  readyState: 'complete',
  get cookie() { return cookie; },
  set cookie(value) { cookie = value; },
  querySelectorAll(selector) {
    if (selector === '[data-gf-cookie-banner]') return [banner];
    if (selector === '[data-gf-cookie-open]') return [link];
    throw Error('unexpected DOM query: ' + selector);
  },
  querySelector(selector) {
    if (selector === '[data-gf-cookie-banner] [data-gf-cookie-action="reject"]') return reject;
    throw Error('unexpected focus selector');
  }
};
const window = {
  location: {protocol: 'https:'},
  dispatchEvent(event) { events.push(event.detail); }
};
class CustomEvent {
  constructor(name, props) { this.name = name; this.detail = props.detail; }
}
vm.runInNewContext(code, {window, document, CustomEvent}, {timeout: 1200});
const api = window.GrindFlowCookieConsent;
assert.strictEqual(api.getChoice(), 'rejected');
assert.strictEqual(api.optionalAllowed(), false);
assert.strictEqual(banner.hidden, false);
assert.strictEqual(cookie, '');
function click(action) {
  bannerHooks.click({
    target: {closest() {return {getAttribute() {return action; }}; }}
  });
}
click('accept');
assert.strictEqual(api.getChoice(), 'accepted');
assert.strictEqual(api.optionalAllowed(), true);
assert.strictEqual(banner.hidden, true);
assert.ok(cookie.includes('gf_cookie_consent_v1=accepted'));
for (const marker of ['Max-Age=15552000', 'Path=/', 'SameSite=Lax', 'Secure']) {
  assert.ok(cookie.includes(marker), marker);
}
linkHooks.click({preventDefault() {}});
assert.strictEqual(api.getChoice(), 'rejected');
assert.strictEqual(api.optionalAllowed(), false);
assert.strictEqual(banner.hidden, false);
assert.strictEqual(focused, true);
assert.ok(cookie.includes('gf_cookie_consent_v1=rejected'));
bannerHooks.keydown({key: 'Escape', preventDefault() {}});
assert.strictEqual(banner.hidden, true);
assert.strictEqual(api.getChoice(), 'rejected');
assert.strictEqual(events[0].choice, 'accepted');
assert.strictEqual(events[events.length - 1].optionalAllowed, false);
console.log('cookie-consent runtime OK');
"""
        result = subprocess.run(
            [node, "-e", runner, str(JS)],
            capture_output=True,
            text=True,
            timeout=8,
            check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr[-1600:])
        self.assertIn("cookie-consent runtime OK", result.stdout)


if __name__ == "__main__":
    unittest.main()
