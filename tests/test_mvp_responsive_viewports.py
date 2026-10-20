"""Real Chromium DOM tests at exact 390x844 and 768x900 fixture viewports.

Local synthetic layout and adverse states only, with no production traffic.
"""
from __future__ import annotations

import base64
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest


INNER = r'''<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
*{box-sizing:border-box}
html,body{margin:0;width:100%;max-width:100%}
body{padding:12px;font:16px sans-serif;overflow-wrap:anywhere}
header,main,nav,form{min-width:0;max-width:100%}
form{display:flex;flex-wrap:wrap;gap:8px}
input{min-width:0;flex:1 1 160px}
button{min-width:44px;min-height:44px}
.grid{display:grid;grid-template-columns:minmax(0,1fr);gap:12px}
.card{min-width:0;padding:16px;border:1px solid #777}
[hidden]{display:none!important}
@media(min-width:700px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style></head><body><header><h1>GrindFlow MVP</h1></header><main>
<form id="filters"><label for="filter">Filter</label>
<input id="filter" type="search"><button id="apply" type="submit">Apply</button></form>
<div id="cards" class="grid"><article class="card">One</article>
<article class="card">Two</article></div>
<nav aria-label="Pagination"><button type="button" id="next">Next page</button>
<output id="page" aria-live="polite">1</output></nav>
<section id="loading" role="status">Loading</section>
<section id="empty" role="status" hidden>Empty</section>
<section id="error" role="alert" hidden>Error</section></main>
<script>
const fault=__FAULT__;
const next=document.querySelector('#next'),page=document.querySelector('#page');
if(fault!=='pagination'){next.addEventListener('click',()=>{
page.textContent=String(Number(page.textContent)+1)})}
if(fault==='overflow'){const wide=document.createElement('div');
wide.style.width='1600px';wide.style.height='1px';document.body.appendChild(wide)}
for(const kind of ['loading','empty','error']){
if(fault===kind){const node=document.getElementById(kind);
node.removeAttribute('role');node.removeAttribute('aria-live')}}
</script></body></html>'''

OUTER = r'''<!doctype html><html lang="en"><head><meta charset="utf-8"></head>
<body><p id="result">RUNNING</p><script>
const width=__WIDTH__,height=__HEIGHT__,mode=__MODE__;
const frame=document.createElement('iframe');
frame.width=String(width);frame.height=String(height);
frame.style.width=width+'px';frame.style.height=height+'px';
frame.style.border='0';frame.title='Disposable MVP viewport';
const result=document.getElementById('result');
function check(value,reason){if(!value)throw Error(reason)}
function announced(element){return ['status','alert','log'].includes(element.getAttribute('role'))
||['polite','assertive'].includes(element.getAttribute('aria-live'))}
frame.onload=()=>{
try{
const win=frame.contentWindow,doc=frame.contentDocument;
check(!!win&&!!doc,'iframe DOM unavailable');
check(win.innerWidth===width&&win.innerHeight===height,'incorrect viewport dimensions');
if(mode==='overflow'){
const widthObserved=Math.max(doc.documentElement.scrollWidth,doc.body.scrollWidth);
check(widthObserved<=win.innerWidth+1,'horizontal overflow');
const cards=doc.getElementById('cards');
check(cards.getBoundingClientRect().right<=win.innerWidth+1,'cards outside viewport');
const columns=win.getComputedStyle(cards).gridTemplateColumns.split(' ').filter(Boolean);
check(columns.length===(width>=700?2:1),'responsive grid breakpoint');
result.textContent='PASS: responsive viewport';
}else if(mode==='states'){
const loading=doc.getElementById('loading'),empty=doc.getElementById('empty');
const error=doc.getElementById('error');
check(!loading.hidden&&announced(loading),'loading announcement');
loading.hidden=true;empty.hidden=false;
check(!empty.hidden&&win.getComputedStyle(empty).display!=='none'
&&announced(empty),'empty announcement');
empty.hidden=true;error.hidden=false;
check(!error.hidden&&win.getComputedStyle(error).display!=='none'
&&announced(error),'error announcement');
const next=doc.getElementById('next');next.focus();
const box=next.getBoundingClientRect();
check(doc.activeElement===next&&box.left>=0&&box.right<=win.innerWidth+1
&&box.width>=44&&next.textContent.trim()!=='','pagination inaccessible');
next.click();
check(doc.getElementById('page').textContent.trim()==='2','pagination did not advance');
result.textContent='PASS: responsive pagination and states';
}else{throw Error('invalid test mode')}
}catch(error){result.textContent='FAIL: '+error.message}
};
document.body.appendChild(frame);
frame.srcdoc=atob('__INNER_B64__');
</script></body></html>'''


def chrome_binary() -> str:
    configured = os.environ.get('GRINDFLOW_BROWSER_CHROME') or os.environ.get('CHROME_BIN')
    if configured:
        resolved = shutil.which(configured) or (configured if Path(configured).is_file() else None)
        if resolved:
            return resolved
    for name in ('google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'):
        resolved = shutil.which(name)
        if resolved:
            return resolved
    raise AssertionError('Chrome/Chromium real obligatorio: sin mocks ni skips')


class MvpResponsiveViewportsTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.chrome = chrome_binary()

    def browser_result(self, width: int, height: int, mode: str, fault: str = '') -> str:
        self.assertIn((width, height), ((390, 844), (768, 900)))
        self.assertIn(mode, ('overflow', 'states'))
        self.assertIn(fault, ('', 'overflow', 'pagination', 'loading', 'empty', 'error'))
        with tempfile.TemporaryDirectory(prefix='gf-responsive-') as directory:
            inner = INNER.replace('__FAULT__', json.dumps(fault))
            encoded = base64.b64encode(inner.encode('ascii')).decode('ascii')
            html = (OUTER.replace('__WIDTH__', str(width))
                    .replace('__HEIGHT__', str(height))
                    .replace('__MODE__', json.dumps(mode))
                    .replace('__INNER_B64__', encoded))
            path = Path(directory) / 'fixture.html'
            path.write_text(html, encoding='utf-8')
            command = [
                self.chrome, '--headless=new', '--no-sandbox', '--disable-gpu',
                '--disable-dev-shm-usage', '--disable-background-networking',
                '--no-first-run', '--user-data-dir=' + str(Path(directory) / 'profile'),
                '--window-size=1024,1100', '--virtual-time-budget=3000',
                '--dump-dom', path.resolve().as_uri(),
            ]
            completed = subprocess.run(
                command, capture_output=True, text=True, timeout=30, check=False,
            )
            self.assertEqual(0, completed.returncode, completed.stderr[-600:])
            marker = re.search(r'<p id="result">([^<]+)</p>', completed.stdout)
            self.assertIsNotNone(marker, 'Chromium no publico el DOM renderizado')
            return marker.group(1)

    def test_mobile_viewports_have_no_horizontal_overflow(self) -> None:
        for width, height in ((390, 844), (768, 900)):
            with self.subTest(width=width, height=height):
                self.assertEqual(
                    'PASS: responsive viewport', self.browser_result(width, height, 'overflow'),
                )
                self.assertEqual(
                    'FAIL: horizontal overflow',
                    self.browser_result(width, height, 'overflow', 'overflow'),
                )

    def test_pagination_and_empty_error_states_at_mobile_widths(self) -> None:
        for width, height in ((390, 844), (768, 900)):
            with self.subTest(width=width, height=height):
                self.assertEqual(
                    'PASS: responsive pagination and states',
                    self.browser_result(width, height, 'states'),
                )
        for fault, expected in (
            ('pagination', 'FAIL: pagination did not advance'),
            ('loading', 'FAIL: loading announcement'),
            ('empty', 'FAIL: empty announcement'),
            ('error', 'FAIL: error announcement'),
        ):
            with self.subTest(fault=fault):
                self.assertEqual(expected, self.browser_result(390, 844, 'states', fault))


if __name__ == '__main__':
    unittest.main()
