"""Actual Chromium DOM and keyboard/focus contracts on disposable MVP fixtures.

No production traffic, credentials, browser mocks, or external dependencies.
"""
from __future__ import annotations

import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


HTML = r'''<!doctype html>
<html lang="es"><head><meta charset="utf-8"><title>GrindFlow QA</title>
<style>
:focus {outline: 3px solid black; outline-offset: 2px}
[hidden] {display: none !important}
</style></head><body>
<form id="filters"><label for="query">Filtrar publicaciones</label>
<input id="query" name="query" type="search" autocomplete="off">
<button type="submit" id="apply">Aplicar</button></form>
<nav aria-label="Paginación"><button id="next" type="button">Página siguiente</button></nav>
<output id="page" aria-live="polite">1</output>
<section id="loading" role="status" aria-live="polite" hidden>Cargando…</section>
<section id="empty" role="status" aria-live="polite" hidden>Sin resultados</section>
<section id="error" role="alert" hidden>Ocurrió un error</section>
<p id="result">RUNNING</p>
<script>
const fault = new URL(location.href).searchParams.get('fault') || '';
const form = document.querySelector('#filters');
const query = document.querySelector('#query');
const next = document.querySelector('#next');
const page = document.querySelector('#page');
const result = document.querySelector('#result');
const loading = document.querySelector('#loading');
const empty = document.querySelector('#empty');
const error = document.querySelector('#error');
if (fault === 'label') document.querySelector('label').remove();
if (fault === 'focus') query.disabled = true;
if (fault === 'live') loading.removeAttribute('aria-live');
form.addEventListener('submit', event => {
  event.preventDefault();
  empty.hidden = query.value !== 'nothing';
});
if (fault !== 'pagination') next.addEventListener('keydown', event => {
  if (event.key === 'Enter') {
    event.preventDefault();
    page.textContent = String(Number(page.textContent) + 1);
  }
});
function check(value, reason) { if (!value) throw Error(reason); }
try {
  if (location.hash === '#keyboard') {
    query.focus();
    check(document.activeElement === query, 'keyboard focus target');
    check(document.querySelector('label[for="query"]')?.textContent.trim(), 'form label');
    check(getComputedStyle(query).outlineStyle !== 'none', 'focus visibility');
    next.focus();
    check(document.activeElement === next, 'pagination focus target');
    next.dispatchEvent(new KeyboardEvent('keydown', {key:'Enter', bubbles:true}));
    check(page.textContent.trim() === '2', 'keyboard pagination');
    check(page.getAttribute('aria-live') === 'polite', 'page feedback');
    result.textContent = 'PASS: keyboard forms, filters and pagination';
  } else if (location.hash === '#states') {
    loading.hidden = false;
    check(loading.getAttribute('role') === 'status' &&
          loading.getAttribute('aria-live') === 'polite', 'loading announced');
    loading.hidden = true;
    query.value = 'nothing';
    form.dispatchEvent(new Event('submit', {cancelable:true, bubbles:true}));
    check(!empty.hidden && empty.getAttribute('role') === 'status' &&
          empty.getAttribute('aria-live') === 'polite', 'empty announced');
    empty.hidden = true;
    error.hidden = false;
    check(!error.hidden && error.getAttribute('role') === 'alert', 'error announced');
    result.textContent = 'PASS: loading, empty and error announcements';
  } else { throw Error('missing test route'); }
} catch (e) { result.textContent = 'FAIL: ' + e.message; }
</script></body></html>
'''


def chrome_binary() -> str:
    configured = os.environ.get('GRINDFLOW_BROWSER_CHROME') or os.environ.get('CHROME_BIN')
    if configured:
        candidate = shutil.which(configured) or (configured if Path(configured).is_file() else None)
        if candidate:
            return candidate
    for name in ('google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'):
        candidate = shutil.which(name)
        if candidate:
            return candidate
    raise AssertionError('Chrome/Chromium real es obligatorio; no se permite skip ni mock')


class MvpA11yKeyboardStatesTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.chrome = chrome_binary()

    def browser_dom(self, route: str, fault: str = '') -> str:
        with tempfile.TemporaryDirectory(prefix='grindflow-a11y-') as directory:
            html = Path(directory) / 'fixture.html'
            html.write_text(HTML, encoding='utf-8')
            url = html.resolve().as_uri() + ('?fault=' + fault if fault else '') + '#' + route
            completed = subprocess.run(
                [self.chrome, '--headless=new', '--no-sandbox', '--disable-gpu',
                 '--disable-dev-shm-usage', '--disable-background-networking',
                 '--no-first-run', '--virtual-time-budget=1500', '--dump-dom', url],
                text=True, capture_output=True, timeout=25, check=False,
            )
            self.assertEqual(completed.returncode, 0, completed.stderr[-600:])
            marker = '<p id="result">'
            self.assertIn(marker, completed.stdout, 'Chrome no publicó el resultado DOM')
            return completed.stdout.split(marker, 1)[1].split('</p>', 1)[0]

    def test_forms_filters_and_pagination_keyboard_focus(self) -> None:
        valid = self.browser_dom('keyboard')
        self.assertEqual('PASS: keyboard forms, filters and pagination', valid)
        for fault in ('label', 'focus', 'pagination'):
            with self.subTest(fault=fault):
                negative = self.browser_dom('keyboard', fault)
                self.assertTrue(negative.startswith('FAIL: '), negative)

    def test_loading_empty_and_error_states_accessible(self) -> None:
        valid = self.browser_dom('states')
        self.assertEqual('PASS: loading, empty and error announcements', valid)
        negative = self.browser_dom('states', 'live')
        self.assertEqual('FAIL: loading announced', negative)


if __name__ == '__main__':
    unittest.main()
