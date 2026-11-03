#!/usr/bin/env python3
"""GF#758: execute the actual WorkspaceNavigation TSX with an offline JSX compiler.

This local component contract does not claim Chromium, Symfony preview or live.
The separate Playwright E2E suite is required for browser focus and responsive CSS.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
COMPONENT = ROOT / "symfony/frontend/admin/WorkspaceNavigation.tsx"
PREVIEW = ROOT / "symfony/frontend/admin/PreviewApp.tsx"
ADMIN = ROOT / "symfony/frontend/admin/AdminApp.tsx"

# Lower and execute the *real* component without npm dependencies; fake React supplies
# hooks, JSX tree construction and browser scroll/resize primitives.
JS_RUNNER = r"""
'use strict';
const fs = require('fs');
const source = fs.readFileSync(process.argv[1], 'utf8');
// Fail closed if future product code tries an unexpected real network call.
global.fetch = () => { throw Error('UNKNOWN: external network forbidden'); };
global.WebSocket = class { constructor() { throw Error('UNKNOWN: websocket forbidden'); } };
// Restricted offline TSX lowering for the real, known WorkspaceNavigation component.
// Any unrecognized syntax must FAIL, never silently replace component behavior.
function lowerWorkspaceTSX(source) {
  function replaceOnce(regex, replacement, label) {
    if (!regex.test(source)) throw Error('Unsupported TSX source: ' + label);
    source = source.replace(regex, replacement);
  }
  replaceOnce(/^import\s+\{\s*useLayoutEffect,\s*useRef,\s*type ReactNode\s*\}\s+from\s+'react';/m, '', 'React imports');
  replaceOnce(/^export type WorkspaceNavItem = Readonly<\{[^}]*\}>;/m, '', 'item type');
  replaceOnce(/^type WorkspaceNavigationProps = Readonly<\{[^}]*\}>;/m, '', 'props type');
  replaceOnce(/export function WorkspaceNavigation\(\{([\s\S]*?)\}: WorkspaceNavigationProps\)/m,
    (_, fields) => 'function WorkspaceNavigation({' + fields + '})', 'component signature');
  replaceOnce(/useRef<HTMLElement \| null>/, 'useRef', 'reference generic');
  replaceOnce(/querySelector<HTMLElement>/, 'querySelector', 'selector generic');
  function quoted(text, start) {
    const quote = text[start];
    let i = start + 1;
    for (; i < text.length; i++) {
      if (text[i] === '\\') { i++; continue; }
      if (text[i] === quote) return i + 1;
    }
    throw Error('Unterminated quoted literal');
  }
  function expression(text, pos) {
    if (text[pos] !== '{') throw Error('Missing expression start');
    let depth = 1, i = pos + 1, result = '';
    for (; i < text.length;) {
      const c = text[i];
      if (c === "'" || c === '"' || c === '`') {
        const end = quoted(text, i); result += text.slice(i, end); i = end;
      } else if (c === '/' && text[i+1] === '/') {
        let end = text.indexOf('\n', i); if (end < 0) end = text.length;
        result += text.slice(i, end); i = end;
      } else if (c === '/' && text[i+1] === '*') {
        const end = text.indexOf('*/', i+2); if (end < 0) throw Error('Unclosed comment');
        result += text.slice(i, end+2); i = end+2;
      } else if (c === '<' && /[A-Za-z>]/.test(text[i+1] || '')) {
        const jsx = jsxNode(text, i); result += jsx.js; i = jsx.next;
      } else if (c === '{') { depth++; result += c; i++; }
      else if (c === '}') {
        depth--;
        if (!depth) return { js: '(' + result + ')', next: i+1 };
        result += c; i++;
      } else { result += c; i++; }
    }
    throw Error('Unterminated JSX expression');
  }
  function jsxNode(text, start) {
    let pos = start + 1;
    const fragment = text[pos] === '>';
    let tag = '';
    if (fragment) { pos++; }
    else {
      const match = /^[A-Za-z][\w-]*/.exec(text.slice(pos));
      if (!match) throw Error('Invalid JSX tag');
      tag = match[0]; pos += tag.length;
    }
    const attrs = [];
    let selfClosed = false;
    if (!fragment) {
      for (;;) {
        while (/\s/.test(text[pos] || '')) pos++;
        if (text.startsWith('/>', pos)) { pos += 2; selfClosed = true; break; }
        if (text[pos] === '>') { pos++; break; }
        const match = /^[A-Za-z_][\w:-]*/.exec(text.slice(pos));
        if (!match) throw Error('Unsupported JSX attribute near ' + text.slice(pos, pos+25));
        const attr = match[0]; pos += attr.length;
        while (/\s/.test(text[pos] || '')) pos++;
        let value = 'true';
        if (text[pos] === '=') {
          pos++;
          while (/\s/.test(text[pos] || '')) pos++;
          if (text[pos] === '{') { const sub = expression(text, pos); value = sub.js; pos = sub.next; }
          else if (text[pos] === '"' || text[pos] === "'") {
            const end = quoted(text, pos); value = JSON.stringify(text.slice(pos+1, end-1)); pos = end;
          } else throw Error('Invalid JSX attribute value');
        }
        if (attrs.some(x => x[0] === attr)) throw Error('Duplicate JSX attribute ' + attr);
        attrs.push([attr, value]);
      }
    }
    const children = [];
    if (!selfClosed) {
      while (pos < text.length) {
        if (text.startsWith('</', pos)) {
          pos += 2;
          const expected = fragment ? '' : tag;
          if (!text.startsWith(expected + '>', pos)) throw Error('Mismatched JSX close ' + tag);
          pos += expected.length + 1;
          break;
        }
        if (text[pos] === '<') {
          const child = jsxNode(text, pos); children.push(child.js); pos = child.next;
        } else if (text[pos] === '{') {
          const sub = expression(text, pos); children.push(sub.js); pos = sub.next;
        } else {
          let next = pos;
          while (next < text.length && text[next] !== '{' && text[next] !== '<') next++;
          const plain = text.slice(pos, next).replace(/\s+/g, ' ').trim();
          if (plain) children.push(JSON.stringify(plain));
          pos = next;
        }
      }
      if (pos >= text.length && !text.endsWith('</'+tag+'>')) {
        // A fully parsed JSX node always includes a matching close tag.
        throw Error('Unterminated JSX node ' + tag);
      }
    }
    if (children.length) attrs.push(['children', children.length===1?children[0]:'['+children.join(',')+']']);
    return {js:'__jsx('+JSON.stringify(fragment?'fragment':tag)+', {' + attrs.map(([k,v])=>JSON.stringify(k)+':'+v).join(',') + '})', next:pos};
  }
  // Replace JSX start tokens only outside comments and quoted JS strings.
  let i = 0, result = '';
  while (i < source.length) {
    const c = source[i];
    if (c === "'" || c === '"' || c === '`') {
      const end = quoted(source,i); result += source.slice(i,end); i=end;
    } else if (c === '/' && source[i+1] === '/') {
      let end=source.indexOf('\n',i); if(end<0)end=source.length;
      result+=source.slice(i,end); i=end;
    } else if (c === '<' && /[A-Za-z>]/.test(source[i+1] || '')) {
      const node=jsxNode(source,i); result += node.js; i=node.next;
    } else { result += c; i++; }
  }
  return result + '\nreturn WorkspaceNavigation;';
}
const refs = [];
const effects = [];
const react = {
  useRef(value) { const ref = { current: value }; refs.push(ref); return ref; },
  useLayoutEffect(callback) { effects.push(callback); },
};
const element = (type, props, key) => ({ type, props: props || {}, key });
const component = (new Function('useRef', 'useLayoutEffect', '__jsx',
  lowerWorkspaceTSX(source)))(react.useRef, react.useLayoutEffect, element);
if (typeof component !== 'function') throw Error('Real export not called');
function findAll(tree, predicate) {
  if (tree === null || tree === undefined || typeof tree === 'boolean') return [];
  if (Array.isArray(tree)) return tree.flatMap(x => findAll(x, predicate));
  if (typeof tree !== 'object' || !('type' in tree)) return [];
  return [ ...(predicate(tree) ? [tree] : []),
    ...findAll(tree.props && tree.props.children, predicate) ];
}
function simulate(options) {
  refs.length = 0;
  effects.length = 0;
  const events = { added: 0, removed: 0, cb: null };
  let globalScrollTouched = false;
  global.window = {
    addEventListener(type, cb) {
      if (type !== 'resize') throw Error('unexpected event');
      events.added++; events.cb = cb;
    },
    removeEventListener(type, cb) {
      if (type !== 'resize' || cb !== events.cb) throw Error('bad cleanup');
      events.removed++;
    },
    scrollTo() { globalScrollTouched = true; },
  };
  let selectedRect = { left: 249, right: 290 };
  const selected = options.selected === false ? null : {
    getBoundingClientRect() { return selectedRect; },
  };
  const nav = {
    scrollWidth: options.overflow === false ? 200 : 500,
    clientWidth: 200,
    scrollLeft: 0,
    querySelector(selector) {
      if (selector !== '.workspace-nav-item.active, .workspace-nav-item.selected')
        throw Error('unexpected active selector');
      return selected;
    },
    getBoundingClientRect() { return { left: 0, right: 200 }; },
  };
  const calls = [];
  const items = options.items || [
    { id:'ideas', label:'Ideas', number:'01', href:'/admin/ideas', stage:'beta' },
    { id:'agenda', label:'Agenda', number:'02', href:'#agenda' },
  ];
  const tree = component({
    mode: options.mode || 'preview',
    items,
    active: options.active === undefined ? 'agenda' : options.active,
    footer: 'synthetic footer',
    ...(options.callback === false ? {} : { onSelect(id) { calls.push(id); } }),
  });
  const navNode = findAll(tree, x=>x.type === 'nav')[0];
  if (!navNode || !navNode.props.ref) throw Error('missing real nav reference');
  if (!options.missingRef) navNode.props.ref.current = nav;
  const buttons = findAll(navNode, x=>x.type === 'button').map(x=>({
    pressed:x.props['aria-pressed'], type:x.props.type,
    cls:x.props.className, click:x.props.onClick,
  }));
  const anchors = findAll(navNode, x=>x.type === 'a').map(x=>({
    href:x.props.href, current:x.props['aria-current'], cls:x.props.className,
  }));
  // Inspect real JSX structure as well as callbacks: the shared brand,
  // scroll affordance, ordered number/title hierarchy, and private/public label.
  const rootAnchor = findAll(tree, x=>x.type === 'a' &&
    x.props.className === 'workspace-brand')[0];
  const shellLabel = findAll(tree, x=>x.props &&
    x.props.className === 'workspace-navigation-label')[0];
  const hint = findAll(tree, x=>x.props &&
    x.props.className === 'workspace-navigation-scroll-hint')[0];
  const footerNode = findAll(tree, x=>x.props &&
    x.props.className === 'workspace-navigation-footer')[0];
  const numberNodes = findAll(navNode, x=>x.props &&
    x.props.className === 'workspace-nav-number');
  const titleNodes = findAll(navNode, x=>x.props &&
    x.props.className === 'workspace-nav-title');
  const stageNodes = findAll(navNode, x=>x.props &&
    x.props.className === 'workspace-nav-stage');
  const childrenText = node => {
    if (Array.isArray(node)) return node.map(childrenText).join('');
    if (node === null || node === undefined || typeof node === 'boolean') return '';
    if (typeof node !== 'object') return String(node);
    return childrenText(node.props && node.props.children);
  };
  const shell = {
    brandHref: rootAnchor && rootAnchor.props.href,
    brandLabel: rootAnchor && rootAnchor.props['aria-label'],
    brandText: rootAnchor && childrenText(rootAnchor),
    workspaceLabel: shellLabel && childrenText(shellLabel),
    scrollHint: hint && childrenText(hint),
    footerText: footerNode && childrenText(footerNode),
    itemNumbers: numberNodes.map(n => [childrenText(n), n.props['aria-hidden']]),
    itemTitles: titleNodes.map(childrenText),
    itemStages: stageNodes.map(childrenText),
  };
  const cleanup = effects[0]();
  const initialScroll = nav.scrollLeft;
  // Exercise EACH real button, not just the first one. A hardcoded ID must fail.
  for (const button of buttons) button.click();
  if (options.resize === true && events.cb) {
    selectedRect = { left: -31, right: 5 };
    events.cb();
  }
  const resizeScroll = nav.scrollLeft;
  if (typeof cleanup === 'function') cleanup();
  return {
    rootClass: tree.props.className,
    navLabel: navNode.props['aria-label'],
    shell,
    buttons: buttons.map(({click, ...rest})=>rest),
    anchors, calls, initialScroll, resizeScroll,
    events: { added: events.added, removed: events.removed },
    globalScrollTouched,
  };
}
const cases = {
  preview: {mode:'preview', resize:false},
  admin: {mode:'admin', resize:false},
  adminPage: {mode:'admin', active:'ideas', resize:false},
  scroll: {mode:'preview', resize:true},
  empty: {mode:'preview', items:[], selected:false},
  unknown: {mode:'preview', active:'not-in-menu', selected:false, callback:false},
  absent: {mode:'admin', missingRef:true},
  noOverflow: {mode:'preview', overflow:false},
  noSelected: {mode:'preview', selected:false},
  noCallback: {mode:'preview', callback:false},
  adminFallback: {mode:'admin', items:[{id:'details', label:'Details', number:'03'}], active:'details'},
};
const answer = {};
for (const [key, opts] of Object.entries(cases)) answer[key] = simulate(opts);
process.stdout.write(JSON.stringify(answer));
"""


def behavior() -> dict:
    """No network or credentials; only local Node and the checked-in TSX file."""
    if not COMPONENT.is_file():
        raise AssertionError("UNKNOWN: real component missing")
    env = {"PATH": os.environ.get("PATH", "")}
    node_path = os.environ.get("NODE_PATH")
    if node_path:
        env["NODE_PATH"] = node_path
    proc = subprocess.run(
        ["node", "-e", JS_RUNNER, str(COMPONENT)],
        cwd=ROOT, env=env, text=True, capture_output=True,
        timeout=30, check=False,
    )
    if proc.returncode or proc.stderr:
        raise AssertionError("UNKNOWN: component runner failed: " + proc.stderr[:700])
    return json.loads(proc.stdout)


class CoberturaGrindflow05Tests(unittest.TestCase):
    def test_contrato_principal(self):
        facts = behavior()
        preview, admin, scroll = (facts[key] for key in ("preview", "admin", "scroll"))
        self.assertIn("preview-aside", preview["rootClass"])
        self.assertEqual(preview["navLabel"], "Explorador de secciones")
        # Exercise the real JSX's common branding, hierarchy and visible hint.
        # No static lookups of code strings can replace these tree assertions.
        for surface, expected in ((preview, "VISTA PREVIA"), (admin, "ESPACIO PRIVADO")):
            with self.subTest(mode=expected):
                shell = surface["shell"]
                self.assertEqual(shell["brandHref"], "/")
                self.assertEqual(shell["brandLabel"], "GrindFlow, inicio")
                self.assertIn("GRINDFLOW", shell["brandText"])
                self.assertEqual(shell["workspaceLabel"], expected)
                self.assertEqual(shell["scrollHint"], "Desliza para explorar más secciones ↔")
                self.assertEqual(shell["footerText"], "synthetic footer")
                self.assertEqual(shell["itemNumbers"], [["01", "true"], ["02", "true"]])
                self.assertEqual(shell["itemTitles"], ["Ideas", "Agenda"])
                self.assertEqual(shell["itemStages"], ["beta"])
        self.assertEqual([b["pressed"] for b in preview["buttons"]], [False, True])
        # CSS class drives both highlighted selection and the real nav's
        # querySelector('.workspace-nav-item.active, .workspace-nav-item.selected').
        # Keep it synchronized with ARIA so focus/scroll can find the active item.
        self.assertEqual(
            [" selected" in b["cls"] for b in preview["buttons"]],
            [False, True],
        )
        self.assertTrue(all(b["type"] == "button" for b in preview["buttons"]))
        self.assertEqual(preview["calls"], ["ideas", "agenda"])
        self.assertEqual(preview["anchors"], [])
        self.assertIn("admin-sidebar", admin["rootClass"])
        self.assertEqual(admin["navLabel"], "Navegación administrativa")
        self.assertEqual(admin["buttons"], [])
        self.assertEqual([a["href"] for a in admin["anchors"]], ["/admin/ideas", "#agenda"])
        self.assertEqual([a.get("current") for a in admin["anchors"]], [None, "location"])
        self.assertEqual(
            [" active" in a["cls"] for a in admin["anchors"]],
            [False, True],
        )
        self.assertEqual(admin["calls"], [])
        self.assertEqual([a.get("current") for a in facts["adminPage"]["anchors"]], ["page", None])
        self.assertEqual(
            [" active" in a["cls"] for a in facts["adminPage"]["anchors"]],
            [True, False],
        )
        self.assertEqual(scroll["initialScroll"], 98)
        self.assertEqual(scroll["resizeScroll"], 59)
        self.assertEqual(scroll["events"], {"added": 1, "removed": 1})
        self.assertFalse(scroll["globalScrollTouched"])
        # Both surfaces must actually consume the same real component.
        # This wiring check supplements (does not replace) its execution above.
        for app, mode in ((PREVIEW, 'preview'), (ADMIN, 'admin')):
            with self.subTest(app=str(app.name)):
                source = app.read_text(encoding='utf-8')
                self.assertIn("import { WorkspaceNavigation } from './WorkspaceNavigation';", source)
                self.assertIn('<WorkspaceNavigation mode="' + mode + '"', source)
        # Private links without a configured href must fail closed to a local fragment.
        self.assertEqual(facts['adminFallback']['anchors'][0]['href'], '#contenido')
        self.assertEqual(facts['adminFallback']['anchors'][0]['current'], 'page')
        self.assertFalse(any(result['globalScrollTouched'] for result in facts.values()))

    def test_falla_cerrado(self):
        facts = behavior()
        self.assertEqual(facts["empty"]["buttons"], [])
        self.assertEqual(facts["empty"]["initialScroll"], 0)
        self.assertEqual(facts["unknown"]["calls"], [])
        self.assertTrue(all(not b["pressed"] for b in facts["unknown"]["buttons"]))
        self.assertFalse(any(" selected" in b["cls"] for b in facts["unknown"]["buttons"]))
        for key in ("empty", "unknown", "absent", "noOverflow", "noSelected"):
            with self.subTest(case=key):
                self.assertEqual(facts[key]["initialScroll"], 0)
                self.assertEqual(facts[key]["resizeScroll"], 0)
                self.assertFalse(facts[key]["globalScrollTouched"])
        self.assertEqual(facts["absent"]["events"], {"added": 0, "removed": 0})
        self.assertEqual(facts["noOverflow"]["events"], {"added": 1, "removed": 1})
        self.assertEqual(facts['noCallback']['calls'], [])
        self.assertEqual([b['pressed'] for b in facts['noCallback']['buttons']], [False, True])
        self.assertEqual(facts['noCallback']['events'], {'added': 1, 'removed': 1})

    def test_sin_datos_reales(self):
        text = Path(__file__).read_text(encoding="utf-8")
        source = COMPONENT.read_text(encoding="utf-8")
        self.assertIn("WorkspaceNavigation", source)
        self.assertIn("lowerWorkspaceTSX", text)
        self.assertIn("new Function", text)
        self.assertNotIn("https://", JS_RUNNER)
        self.assertNotIn("fetch(", JS_RUNNER)
        self.assertNotIn("matriz-" + "cobertura", text)
        self.assertEqual(set(behavior()), {
            "preview", "admin", "adminPage", "scroll", "empty", "unknown", "absent",
            "noOverflow", "noSelected", "noCallback", "adminFallback",
        })


if __name__ == "__main__":
    unittest.main()
