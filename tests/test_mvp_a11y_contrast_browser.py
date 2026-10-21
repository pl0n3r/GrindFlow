"""Computed WCAG AA checks with disposable Chromium DOM (no external traffic)."""
from __future__ import annotations
import html
import json
import os
from pathlib import Path
import re
import shutil
import signal
import subprocess
import tempfile
import unittest

FIXTURE = r'''<!doctype html><html lang="es"><head><meta charset="utf-8">
<style>
html{background:#fff}body{background:#fbfdff;color:#182433;font:16px/1.5 Arial,sans-serif}
main{margin:24px;padding:20px;background:rgba(230,239,251,.92)}
label{display:block;color:#25364a;margin:10px 0}
input{display:block;border:2px solid #244c65;background:#fff;color:#182433;padding:12px}
button{padding:12px 18px;border:2px solid #103c64;background:#124266;color:#fff;font-weight:700}
.error{padding:12px;background:#fff8f8;color:#8a1b21}
.hero{font-size:26px;font-weight:700;color:#234b69}
.translucent{color:rgba(12,31,55,.92)}
:disabled{opacity:.45}[hidden]{display:none!important}
</style></head><body><main>
<h1 id="headline" data-contrast="text" class="hero">Tu contenido</h1>
<label id="label" data-contrast="text" for="search">Buscar publicaciones</label>
<input id="search" data-contrast="control" aria-label="Buscar publicaciones">
<button id="cta" data-contrast="text" type="button">Publicar</button>
<button id="disabled" data-contrast="text" disabled>Desactivado</button>
<p id="alert" data-contrast="text" class="error" role="alert">Error de validación</p>
<p id="translucent" data-contrast="text" class="translucent">Estado informativo</p>
<p id="hidden" data-contrast="text" hidden>Oculto</p>
<pre id="result">RUNNING</pre></main><script>
const fault=__FAULT__;
if(fault==='text')document.querySelector('#label').style.color='#c3cbd5';
if(fault==='control')document.querySelector('#search').style.borderColor='#dfe4e9';
if(fault==='alpha')document.querySelector('#translucent').style.color='rgba(12,31,55,.12)';
function color(s){
 const m=/^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s/]+([\d.]+))?\s*\)$/.exec(s);
 if(!m)throw Error('unhandled computed color '+s);
 return [+m[1],+m[2],+m[3],m[4]===undefined?1:+m[4]];
}
function blend(x,y){const a=x[3];return [0,1,2].map(i=>x[i]*a+y[i]*(1-a)).concat(1)}
function background(el){
 const parents=[];for(let e=el;e;e=e.parentElement)parents.unshift(e);
 return parents.reduce((bg,e)=>{
   const s=getComputedStyle(e);
   if(s.backgroundImage!=='none')throw Error('unmeasurable background image');
   return blend(color(s.backgroundColor),bg);
 },[255,255,255,1]);
}
function lum(rgb){const c=rgb.slice(0,3).map(n=>{const s=n/255;return s<=.04045?s/12.92:((s+.055)/1.055)**2.4});
 return .2126*c[0]+.7152*c[1]+.0722*c[2]}
function contrast(x,y){const a=lum(x),b=lum(y);return (Math.max(a,b)+.05)/(Math.min(a,b)+.05)}
function invisible(el){
 if(el.closest('[hidden],[aria-hidden="true"],:disabled'))return true;
 for(let e=el;e;e=e.parentElement){
   const s=getComputedStyle(e);
   if(s.display==='none'||s.visibility==='hidden')return true;
   if(+s.opacity<1)throw Error('raster required for non-disabled opacity');
 }return false;
}
function audit(){
 const measurements=[],skipped=[];
 for(const el of document.querySelectorAll('[data-contrast]')){
   if(invisible(el)){skipped.push(el.id);continue}
   const css=getComputedStyle(el),bg=background(el);
   let min,measured;
   if(el.dataset.contrast==='text'){
     const size=parseFloat(css.fontSize),bold=parseFloat(css.fontWeight)>=700;
     min=size>=24||(size>=18.66&&bold)?3:4.5;
     measured=contrast(blend(color(css.color),bg),bg);
   }else{
     if(parseFloat(css.borderTopWidth)<=0)throw Error('control without border');
     min=3;const parent=background(el.parentElement);
     measured=contrast(blend(color(css.borderTopColor),parent),parent);
   }
   measurements.push({id:el.id,ratio:+measured.toFixed(4),minimum:min});
 }
 return {ok:measurements.every(x=>x.ratio>=x.minimum),
   measurements,skipped,failed:measurements.filter(x=>x.ratio<x.minimum).map(x=>x.id)};
}
try{document.getElementById('result').textContent=JSON.stringify(audit())}
catch(error){document.getElementById('result').textContent=JSON.stringify({ok:false,error:String(error.message)})}
</script></body></html>'''


def chromium() -> str:
    configured = os.environ.get("GRINDFLOW_BROWSER_CHROME") or os.environ.get("CHROME_BIN")
    if configured:
        found = shutil.which(configured) or (configured if Path(configured).is_file() else None)
        if found:
            return found
    for name in ("google-chrome", "google-chrome-stable", "chromium", "chromium-browser"):
        if shutil.which(name):
            return shutil.which(name)
    raise AssertionError("Chromium real obligatorio; no mocks o skips")


class MvpA11yContrastBrowserTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.chrome = chromium()

    def render(self, fault=""):
        self.assertIn(fault, ("", "text", "control", "alpha"))
        with tempfile.TemporaryDirectory(prefix="gf-contrast-") as tmp:
            root = Path(tmp)
            page = root / "fixture.html"
            page.write_text(FIXTURE.replace("__FAULT__", json.dumps(fault)), encoding="utf-8")
            cmd = [self.chrome, "--headless=new", "--no-sandbox", "--disable-gpu",
                   "--disable-dev-shm-usage", "--disable-background-networking",
                   "--no-first-run", "--user-data-dir=" + str(root / "profile"),
                   "--virtual-time-budget=2000", "--dump-dom", page.resolve().as_uri()]
            proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                    text=True, start_new_session=True)
            try:
                output, err = proc.communicate(timeout=30)
            except subprocess.TimeoutExpired:
                try:
                    os.killpg(proc.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                proc.communicate()
                self.fail("Chromium timed out (30s)")
            self.assertEqual(proc.returncode, 0, err[-600:])
            match = re.search(r'<pre id="result">([^<]+)</pre>', output)
            self.assertIsNotNone(match, "Chromium did not expose a rendered DOM")
            return json.loads(html.unescape(match.group(1)))

    def test_text_and_controls_meet_contrast_threshold_with_computed_colors(self):
        report = self.render()
        self.assertTrue(report.get("ok"), report)
        values = {x["id"]: x for x in report["measurements"]}
        self.assertEqual(set(values), {"headline", "label", "search", "cta", "alert", "translucent"})
        self.assertEqual(set(report["skipped"]), {"disabled", "hidden"})
        self.assertEqual(values["headline"]["minimum"], 3)
        self.assertEqual(values["search"]["minimum"], 3)
        self.assertEqual(values["label"]["minimum"], 4.5)
        for item in values.values():
            self.assertGreaterEqual(item["ratio"], item["minimum"])

    def test_low_contrast_fixture_fails_and_disabled_or_transparent_states_are_handled(self):
        for mode, target in (("text", "label"), ("control", "search"), ("alpha", "translucent")):
            with self.subTest(mode=mode):
                report = self.render(mode)
                self.assertFalse(report["ok"], report)
                self.assertIn(target, report["failed"])
                self.assertEqual(set(report["skipped"]), {"disabled", "hidden"})
                self.assertNotIn("error", report)


if __name__ == "__main__":
    unittest.main()
