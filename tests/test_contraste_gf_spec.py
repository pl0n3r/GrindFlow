"""Contrato GF662: 40 SPEC y matriz fail-closed sin cobertura inventada."""
from __future__ import annotations

from collections import Counter
from pathlib import Path
import re
import ast
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / 'docs/matriz-cobertura.md'
CONTRAST = ROOT / 'docs/contraste-gf_spec.md'
SOURCE = ROOT / 'docs/GRINDFLOW-SPEC.md'
SOURCE_SHA = 'af275aef3ae1e0b8d4bd2eca6a1a646cac312795'
EXPECTED = {'SPEC:005': 40, 'SPEC:007': 50, 'SPEC:008': 54, 'SPEC:009': 67, 'SPEC:010': 76, 'SPEC:011': 97, 'SPEC:012': 103, 'SPEC:013': 112, 'SPEC:014': 129, 'SPEC:015': 142, 'SPEC:016': 152, 'SPEC:017': 162, 'SPEC:018': 177, 'SPEC:019': 196, 'SPEC:020': 215, 'SPEC:021': 237, 'SPEC:022': 252, 'SPEC:024': 272, 'SPEC:025': 275, 'SPEC:026': 279, 'SPEC:027': 285, 'SPEC:028': 291, 'SPEC:029': 313, 'SPEC:030': 329, 'SPEC:031': 346, 'SPEC:032': 357, 'SPEC:033': 367, 'SPEC:034': 384, 'SPEC:035': 398, 'SPEC:036': 414, 'SPEC:037': 431, 'SPEC:038': 451, 'SPEC:039': 470, 'SPEC:040': 495, 'SPEC:041': 501, 'SPEC:042': 515, 'SPEC:043': 539, 'SPEC:044': 557, 'SPEC:045': 576, 'SPEC:046': 582}
ALLOWED = {'NO_VERIFICABLE', 'CUBIERTO', 'SIN_COBERTURA', 'DESCARTADO'}
PR_LINK = re.compile(r'\[PR #(\d+)\]\(https://github\.com/pl0n3r/GrindFlow/pull/\1\)')


def rows(text: str, prefix='SPEC:') -> dict[str, list[str]]:
    output = {}
    for line in text.splitlines():
        if not line.startswith('| ' + prefix):
            continue
        fields = [cell.strip() for cell in re.split(r'(?<!\\)\|', line.strip('| '))]
        if len(fields) not in (7, 8):
            raise AssertionError('invalid column count: ' + line[:90])
        key = fields[0]
        if key in output:
            raise AssertionError('duplicate ID ' + key)
        output[key] = fields
    return output


def php_executable_code(source: str) -> str:
    """Mask PHP comments, strings, shell strings and heredoc/nowdoc spans.

    Keep newlines and byte positions so a declaration inside a literal cannot
    masquerade as the method implemented by the test file. Fail closed for
    malformed or unclosed lexical spans.
    """
    output = list(source)
    state = 'html'
    quote = ''
    heredoc = ''
    pos = 0
    while pos < len(source):
        rest = source[pos:]
        if state == 'html':
            tag = re.match(r'<\?(?:php(?:\s|$)|=)', rest, re.I)
            if tag:
                state = 'code'
                pos += len(tag.group())
                continue
            if source[pos] != '\n': output[pos] = ' '
            pos += 1
            continue
        if state == 'code':
            if rest.startswith('?>'):
                state = 'html'; pos += 2; continue
            if rest.startswith('/*'):
                state = 'block'; output[pos:pos+2] = '  '; pos += 2; continue
            if rest.startswith('//') or source[pos] == '#':
                state = 'line'; span = 2 if rest.startswith('//') else 1
                output[pos:pos+span] = ' ' * span; pos += span; continue
            if source[pos] in ("'", '"', '`'):
                quote = source[pos]; state = 'quoted'; output[pos] = ' '; pos += 1; continue
            match = re.match(r'<<<[ \t]*([\"\']?)([A-Za-z_][A-Za-z_0-9]*)\1(?=\r?\n)', rest)
            if match:
                heredoc = match.group(2); state = 'heredoc'
                output[pos:pos+len(match.group())] = ' ' * len(match.group())
                pos += len(match.group()); continue
            pos += 1
            continue
        if state == 'line':
            # PHP // and # comments end at a closing ?>, not only at EOL.
            # All following text is inline HTML until a real <?php reopen.
            # Without this, an emitted HTML fragment containing an apparent
            # TestCase method would be counted as executable PHPUnit code.
            if rest.startswith('?>'):
                output[pos:pos+2] = '  '
                pos += 2
                state = 'html'
                continue
            if source[pos] == '\n': state = 'code'
            else: output[pos] = ' '
            pos += 1
            continue
        if state == 'block':
            if rest.startswith('*/'):
                output[pos:pos+2] = '  '; pos += 2; state = 'code'; continue
            if source[pos] != '\n': output[pos] = ' '
            pos += 1
            continue
        if state == 'quoted':
            if source[pos] == '\\' and pos+1 < len(source):
                output[pos:pos+2] = '  '; pos += 2; continue
            if source[pos] == quote: state = 'code'
            if source[pos] != '\n': output[pos] = ' '
            pos += 1
            continue
        if state == 'heredoc':
            # End marker must start a line and have an allowed terminator.
            if (pos == 0 or source[pos-1] == '\n') and re.match(r'\s*' + re.escape(heredoc) + r'(?=\s*[;,]?\s*(?:\r?\n|$))', rest):
                state = 'code'
                pos += len(re.match(r'\s*' + re.escape(heredoc), rest).group())
                continue
            if source[pos] != '\n': output[pos] = ' '
            pos += 1
    if state in ('quoted', 'block', 'heredoc'):
        return ''  # an unfinished span is not sound evidence
    return ''.join(output)


def php_class_at_load_scope(executable: str, class_position: int) -> bool:
    """A PHPUnit class must be load-time declared, never conditional/local.

    Support ordinary global/semicolon namespaces and PHP's bracketed
    namespace syntax; do not mistake an if/function/closure block for one.
    This is structural static evidence only, not runtime execution.
    """
    namespace_braces = {
        match.end() - 1 for match in re.finditer(
            r'(?m)^[ \t]*namespace(?:[ \t]+[A-Za-z_\\][A-Za-z_0-9\\]*)?[ \t]*\{',
            executable,
        )
    }
    stack: list[int] = []
    for pos, ch in enumerate(executable[:class_position]):
        if ch == '{':
            stack.append(pos)
        elif ch == '}':
            if not stack:
                return False
            stack.pop()
    return not stack or (len(stack) == 1 and stack[0] in namespace_braces)


def declares_test_method(path: Path, method: str) -> bool:
    """Only direct PHPUnit/unittest.TestCase methods; never helpers or literals.

    The PHP lexer already masks comments, quoted text, heredocs and inline HTML.
    Respect balanced class braces and require the method at class scope, not a
    global function or a declaration nested inside another method.
    """
    if re.fullmatch(r'test_[A-Za-z_0-9]+|test[A-Z][A-Za-z_0-9]*', method) is None:
        return False
    source = path.read_text(encoding='utf8')
    if path.suffix == '.py':
        try: tree = ast.parse(source)
        except SyntaxError: return False
        for klass in tree.body:
            if not isinstance(klass, ast.ClassDef):
                continue
            # A similarly named method on an ordinary helper class is not
            # unittest coverage. The known repository contract tests inherit
            # directly from unittest.TestCase.
            bases = [ast.unparse(base) for base in klass.bases]
            if not any(base in ('unittest.TestCase', 'TestCase') for base in bases):
                continue
            if any(isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef))
                   and node.name == method for node in klass.body):
                return True
        return False
    if path.suffix == '.php':
        executable = php_executable_code(source)
        if not executable:
            return False
        klass_re = re.compile(
            r'(?m)^[ \t]*(?P<class_modifiers>(?:(?:final|abstract|readonly)\s+)*)class\s+'
            r'[A-Za-z_][A-Za-z_0-9]*\s+extends\s+'
            r'(?:\\?[A-Za-z_][A-Za-z_0-9]*\\)*'
            r'(?:TestCase|WebTestCase|KernelTestCase)\b'
        )
        method_re = re.compile(
            r'(?m)^[ \t]*(?P<method_modifiers>(?:(?:public|protected|private|static|final)\s+)*)'
            r'function\s+' + re.escape(method) + r'\s*\('
        )
        for klass in klass_re.finditer(executable):
            # Abstract PHPUnit classes are not concrete discoverable test cases.
            if 'abstract' in klass.group('class_modifiers').split():
                continue
            if not php_class_at_load_scope(executable, klass.start()):
                continue
            brace = executable.find('{', klass.end())
            if brace < 0 or ';' in executable[klass.end():brace]:
                continue
            depth = 1
            pos = brace + 1
            while pos < len(executable) and depth:
                char = executable[pos]
                if char == '{': depth += 1
                elif char == '}': depth -= 1
                elif depth == 1:
                    matched = method_re.match(executable, pos)
                    if matched:
                        # PHPUnit does not execute private/protected/static test methods.
                        mods = set(matched.group('method_modifiers').split())
                        if not mods.intersection({'private', 'protected', 'static'}):
                            return True
                pos += 1
        return False
    return False


class ContrasteTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source = SOURCE.read_text(encoding='utf8')
        cls.matrix = MATRIX.read_text(encoding='utf8')
        cls.doc = CONTRAST.read_text(encoding='utf8')
        cls.matrix_rows = rows(cls.matrix)
        cls.contrast_rows = rows(cls.doc)

    def test_cada_fila_tiene_veredicto(self):
        self.assertEqual(set(self.matrix_rows), set(EXPECTED))
        self.assertEqual(set(self.contrast_rows), set(EXPECTED))
        lines = self.source.splitlines()
        for key, line_no in EXPECTED.items():
            with self.subTest(key=key):
                cells = self.contrast_rows[key]
                matrix = self.matrix_rows[key]
                self.assertEqual(len(cells), 7)
                self.assertEqual(len(matrix), 8)
                self.assertIn(cells[2], ALLOWED)
                self.assertEqual(cells[2], matrix[2])
                self.assertIn('/blob/' + SOURCE_SHA + '/', cells[1])
                self.assertIn(f'#L{line_no})', cells[1])
                self.assertIn(f'GRINDFLOW-SPEC.md:L{line_no}', cells[1])
                self.assertTrue(1 <= line_no <= len(lines))
                self.assertRegex(lines[line_no - 1], r'^#{2,4}\s+\S')
                self.assertGreater(len(cells[6]), 40)
                if cells[2] == 'NO_VERIFICABLE':
                    self.assertIn('falta', cells[6].lower())
                    self.assertEqual(matrix[5:], ['NO_ACREDITADO'] * 3)

    def test_cubierto_enlaza_prueba_existente(self):
        for key, cells in self.contrast_rows.items():
            with self.subTest(key=key):
                if cells[2] != 'CUBIERTO':
                    if cells[2] == 'NO_VERIFICABLE':
                        self.assertIn('falta', cells[6].lower())
                    elif cells[2] == 'DESCARTADO':
                        self.assertIn('descart', cells[6].lower())
                    continue
                self.assertRegex(cells[5], PR_LINK)
                self.assertIn(cells[5], self.matrix_rows[key][3])
                self.assertNotIn(cells[3], ('—', '-', 'UNKNOWN'))
                self.assertNotIn(cells[4], ('—', '-', 'UNKNOWN'))
                match = re.search(r'\]\(https://github\.com/pl0n3r/GrindFlow/blob/[0-9a-f]{40}/([^#)]+)', cells[3])
                self.assertIsNotNone(match, key)
                path = ROOT / match.group(1)
                self.assertTrue(path.is_file(), str(path))
                self.assertTrue(declares_test_method(path, cells[4].strip('`')), key)
                self.assertTrue(self.matrix_rows[key][5].startswith('IMPLEMENTADO'))
                self.assertNotEqual(self.matrix_rows[key][6], 'NO_ACREDITADO')

    def test_candidatos_con_pr_y_prueba_conservan_traza_local(self):
        # An observed test + merged PR is only a CANDIDATE, never an
        # automatic declaration that the whole SPEC is covered.
        checked = 0
        for key, cells in self.contrast_rows.items():
            with self.subTest(key=key):
                candidate = cells[5] != '—' or cells[3] != '—' or cells[4] != '—'
                if not candidate:
                    continue
                checked += 1
                self.assertEqual(cells[2], 'NO_VERIFICABLE')
                self.assertRegex(cells[5], PR_LINK)
                m = re.fullmatch(
                    r'\[([^]]+)\]\(https://github\.com/pl0n3r/GrindFlow/'
                    r'blob/[0-9a-f]{40}/([^#)]+)#L\d+\)', cells[3]
                )
                self.assertIsNotNone(m, key)
                self.assertEqual(m.group(1), m.group(2))
                path = ROOT / m.group(2)
                self.assertTrue(path.is_file(), f'{key}: {path}')
                self.assertTrue(declares_test_method(path, cells[4].strip('`')), key)
        self.assertEqual(checked, 13)

    def test_declaraciones_no_ejecutables_no_acreditan(self):
        """Negative controls for comments, literals, and nested functions."""
        import tempfile
        with tempfile.TemporaryDirectory(prefix='gf662-method-') as tmp:
            php = Path(tmp) / 'ExampleTest.php'
            python = Path(tmp) / 'ExampleTest.py'
            tests = (
                ('<?php\n/*\n public function testSecure(): void {}\n*/\n', False),
                ('<?php\n$x = "\n public function testSecure(): void {}\n";\n', False),
                ("<?php\n$x = <<<'TXT'\npublic function testSecure(): void {}\nTXT;\n", False),
                ('<?php\n// public function testSecure(): void {}\n', False),
                ('<?php\nclass ExampleTest extends TestCase {\npublic function testSecure(): void {}\n}\n', True),
            )
            for source, expected in tests:
                with self.subTest(source=source[:40]):
                    php.write_text(source, encoding='utf8')
                    self.assertEqual(declares_test_method(php, 'testSecure'), expected)
            python.write_text('def wrapper():\n    def test_nested():\n        pass\n', encoding='utf8')
            self.assertFalse(declares_test_method(python, 'test_nested'))
            python.write_text('class ExampleTests(unittest.TestCase):\n    def test_nested(self):\n        pass\n', encoding='utf8')
            self.assertTrue(declares_test_method(python, 'test_nested'))

    def test_solo_metodos_de_clases_de_test_acreditan(self):
        """Reject global PHP functions, helper methods and plain Python classes."""
        import tempfile
        with tempfile.TemporaryDirectory(prefix='gf662-class-scope-') as tmp:
            php = Path(tmp) / 'ExampleTest.php'
            cases = (
                ('<?php\nfunction testSecure() {}\n', False),
                ('<?php\nclass Utility {\n public function testSecure(): void {}\n}\n', False),
                ('<?php\nclass ExampleTest extends TestCase {}\nfunction testSecure() {}\n', False),
                ('<?php\nclass ExampleTest extends WebTestCase {\n'
                 'public function helper(): void { $unused = "testSecure"; }\n}\n', False),
                ('<?php\nclass ExampleTest extends TestCase {\n'
                 'public function testSecure(): void {}\n}\n', True),
                ('<?php\nclass ExampleTest extends WebTestCase {\n'
                 'public function testSecure(): void {}\n}\n', True),
                ('<?php\nclass ExampleTest extends KernelTestCase {\n'
                 'public function testSecure(): void {}\n}\n', True),
                ('<?php\nclass ExampleTest extends TestCase {\n'
                 'public function wrapper(): void {\n'
                 'function testSecure(): void {}\n}\n}\n', False),
            )
            for source, expected in cases:
                with self.subTest(source=source[:90]):
                    php.write_text(source, encoding='utf8')
                    self.assertEqual(declares_test_method(php, 'testSecure'), expected)
            python = Path(tmp) / 'ExampleTest.py'
            cases_py = (
                ('class Helper:\n    def test_secure(self): pass\n', False),
                ('class Demo(unittest.TestCase):\n'
                 '    def helper(self):\n'
                 '        def test_secure(): pass\n', False),
                ('class Demo(unittest.TestCase):\n'
                 '    def test_secure(self): pass\n', True),
                ('class Demo(TestCase):\n'
                 '    async def test_secure(self): pass\n', True),
            )
            for source, expected in cases_py:
                with self.subTest(source=source[:90]):
                    python.write_text(source, encoding='utf8')
                    self.assertEqual(declares_test_method(python, 'test_secure'), expected)

    def test_cierre_php_en_comentario_no_acredita_html(self):
        """A line-comment ?> closes PHP: emitted HTML is not test code."""
        import tempfile
        with tempfile.TemporaryDirectory(prefix='gf662-php-close-') as temp:
            file = Path(temp) / 'OutsidePhpTest.php'
            cases = (
                ("<?php\n// ?>\nclass FakeTest extends TestCase {\n"
                 " public function testSecure(): void {}\n}\n", False),
                ("<?php\n# ?>\nclass FakeTest extends TestCase {\n"
                 " public function testSecure(): void {}\n}\n", False),
                ("<?php\n// ?>\n<!-- class FakeTest extends TestCase {\n"
                 " public function testSecure(): void {}\n} -->\n", False),
                ("<?php\n// ?>\nclass FakeTest extends TestCase {\n"
                 " public function testSecure(): void {}\n}\n"
                 "<?php\nclass RealTest extends TestCase {\n"
                 " public function testSecure(): void {}\n}\n", True),
                ("<?php\n// ?>\nclass FakeTest extends TestCase {\n"
                 " public function testSecure(): void {}\n}\n"
                 "<?php\nclass RealTest extends TestCase {\n"
                 " public function testDifferent(): void {}\n}\n", False),
                ("<?php\n/* ?> does not close */\n"
                 "class RealTest extends TestCase {\n"
                 " public function testSecure(): void {}\n}\n", True),
                ("<?php\n$x = 'literal // ?>';\n"
                 "class RealTest extends TestCase {\n"
                 " public function testSecure(): void {}\n}\n", True),
            )
            for source, expected in cases:
                with self.subTest(source=source[:55]):
                    file.write_text(source, encoding='utf8')
                    self.assertEqual(declares_test_method(file, 'testSecure'), expected)

    def test_clases_condicionales_php_no_acreditan(self):
        """PHP class declarations in runtime blocks are not PHPUnit discovery."""
        import tempfile
        with tempfile.TemporaryDirectory(prefix='gf662-dynamic-class-') as temp:
            path = Path(temp) / 'DynamicTest.php'
            cases = (
                ("<?php\nfunction register(): void {\n"
                 "class HiddenTest extends TestCase {\n"
                 " public function testSecure(): void {}\n}\n}\n", False),
                ("<?php\nif (false) {\n"
                 "class HiddenTest extends TestCase {\n"
                 " public function testSecure(): void {}\n}\n}\n", False),
                ("<?php\n$register = static function () {\n"
                 "class HiddenTest extends TestCase {\n"
                 " public function testSecure(): void {}\n}\n};\n", False),
                ("<?php\nnamespace Tests;\n"
                 "class RealTest extends TestCase {\n"
                 " public function testSecure(): void {}\n}\n", True),
                ("<?php\nnamespace Tests {\n"
                 "class RealTest extends TestCase {\n"
                 " public function testSecure(): void {}\n}\n}\n", True),
                ("<?php\nnamespace {\n"
                 "class RealTest extends WebTestCase {\n"
                 " public function testSecure(): void {}\n}\n}\n", True),
            )
            for source, expected in cases:
                with self.subTest(source=source[:60]):
                    path.write_text(source, encoding='utf8')
                    self.assertEqual(declares_test_method(path, 'testSecure'), expected)

    def test_phpunit_solo_metodos_publicos_de_clases_concretas(self):
        """A real PHPUnit test must be discoverable, not merely a method signature."""
        import tempfile
        with tempfile.TemporaryDirectory(prefix='gf662-phpunit-visibility-') as tmp:
            path = Path(tmp) / 'VisibilityTest.php'
            cases = (
                ('class DemoTest extends TestCase {\n private function testSecure(): void {}\n}', False),
                ('class DemoTest extends TestCase {\n protected function testSecure(): void {}\n}', False),
                ('class DemoTest extends TestCase {\n public static function testSecure(): void {}\n}', False),
                ('abstract class DemoTest extends TestCase {\n public function testSecure(): void {}\n}', False),
                ('abstract\nclass DemoTest extends TestCase {\n public function testSecure(): void {}\n}', False),
                ('class DemoTest extends TestCase {\n public function testSecure(): void {}\n}', True),
                ('class DemoTest extends TestCase {\n function testSecure(): void {}\n}', True),
                ('final class DemoTest extends TestCase {\n public function testSecure(): void {}\n}', True),
                ('class DemoTest extends TestCase {\n final public function testSecure(): void {}\n}', True),
            )
            for php, expected in cases:
                with self.subTest(php=php.split('{')[0]):
                    path.write_text('<?php\n' + php + '\n', encoding='utf8')
                    self.assertEqual(declares_test_method(path, 'testSecure'), expected)

    def test_faltante_tiene_issue(self):
        for key, cells in self.contrast_rows.items():
            with self.subTest(key=key):
                if cells[2] != 'SIN_COBERTURA':
                    continue
                match = re.search(r'https://github\.com/pl0n3r/GrindFlow/issues/(\d+)', cells[6])
                self.assertIsNotNone(match, key)
                self.assertNotEqual(match.group(1), '2', key)
                self.assertIn('/issues/' + match.group(1), self.matrix_rows[key][4])

    def test_matriz_actualizada(self):
        self.assertEqual(len(self.matrix_rows), 40)
        self.assertIn('## Contraste SPEC #662', self.matrix)
        self.assertIn('[contraste-gf_spec.md](contraste-gf_spec.md)', self.matrix)
        self.assertIn('40 filas', self.matrix)
        self.assertIn('13 candidatos', self.matrix)
        for key, cells in self.matrix_rows.items():
            self.assertEqual(cells[2], self.contrast_rows[key][2])
            self.assertIn('GRINDFLOW-SPEC.md:L', cells[3])
        # El resumen deriva de TODAS las filas, no congela los totales previos.
        state_counts = Counter()
        total = 0
        for line in self.matrix.splitlines():
            if not line.startswith(('| REQ:', '| SPEC:', '| STACK:', '| RM-')):
                continue
            chunks = [x.strip() for x in re.split(r'(?<!\\)\|', line.strip('| '))]
            self.assertEqual(len(chunks), 8)
            state_counts[chunks[2]] += 1
            total += 1
        self.assertGreaterEqual(total, 40)
        labels = ('CUBIERTO', 'EN_CURSO', 'SIN_COBERTURA', 'DESCARTADO', 'NO_VERIFICABLE')
        self.assertFalse(set(state_counts) - set(labels))
        summary = '; '.join(f'{x}={state_counts[x]}' for x in labels)
        self.assertIn(f'Resumen: {summary}; total={total}; porcentaje={100*state_counts["CUBIERTO"]/total:.2f}%.', self.matrix)


if __name__ == '__main__':
    unittest.main()
