#!/usr/bin/env python3
"""GrindFlow #719: SPEC:034-038, source-backed offline evidence only.

A PHPUnit method's assertions prove a *written test*, not a successful run,
protected-branch CI, real tenant ACL, Hostinger, or production readiness.
Every absent, ambiguous or contradictory piece of evidence fails closed.
"""
from __future__ import annotations

from datetime import datetime
from pathlib import Path
import re
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
MATRIX = 'docs/matriz-cobertura.md'
SPEC = 'docs/GRINDFLOW-SPEC.md'
UNKNOWN = 'UNKNOWN'
SOURCE_EVIDENCE = 'SOURCE_ASSERTIONS_PRESENT'
OPERATIONS = ('ci', 'phpunit_run', 'tenant_runtime', 'hostinger',
              'production', 'deployment', 'smoke')

# ID: source line, required section heading, invariant clauses, PHPUnit file,
# three distinct PHPUnit methods exercising relevant positive/negative cases.
CONTRACTS = {
    'SPEC:034': (384, '## 15. Reversible Scheduler-to-Traffic assignment management',
                 ('one tracked link', 'disabled link', 'short-link tokens'),
                 'tests/Feature/ScheduleTrackedLinkTest.php',
                 ('test_manager_can_attach_swap_and_detach_a_link_without_losing_metrics',
                  'test_link_edit_rejects_other_tenant_model_and_invalid_or_disabled_link',
                  'test_link_edit_rejects_cancelled_or_claimed_delivery')),
    'SPEC:035': (398, '## 16. Scheduler calendar: full filtered pagination',
                 ('stable 25-item pages', 'scheduled_for_utc, id', 'Disabled destinations'),
                 'tests/Feature/SchedulingTest.php',
                 ('test_calendar_paginates_all_matching_schedules_without_cross_tenant_leaks',
                  'test_calendar_preserves_filters_across_pages_including_disabled_destinations',
                  'test_scheduler_endpoints_are_safe_before_scheduling_migration')),
    'SPEC:036': (414, '## 17. Finance filtered beneficiary reconciliation (read-only)',
                 ('allocations-vs-reversals', 'complete-group CSV', 'unassigned'),
                 'tests/Feature/FinanceReconciliationTest.php',
                 ('test_all_currency_and_beneficiary_groups_reconcile_with_event_date_filters',
                  'test_full_reconciliation_csv_and_ledger_pagination_do_not_leak_foreign_records',
                  'test_finance_csv_is_private_and_safe_before_schema_migration')),
    'SPEC:037': (431, '## 18. Traffic link management and complete paginated reporting',
                 ('25 links per page', 'tenant-filtered', 'click history'),
                 'tests/Feature/TrafficAttributionTest.php',
                 ('test_editing_link_details_preserves_short_url_clicks_status_and_redirects',
                  'test_tracked_link_index_paginates_deep_history_with_filters_and_global_totals',
                  'test_link_detail_update_is_schema_safe')),
    'SPEC:038': (451, '## 19. Scheduler searchable eligible media and tracked-link options',
                 ('case-insensitive', 'exact UUID', 'first 100'),
                 'tests/Feature/SchedulerPickerSearchTest.php',
                 ('test_media_and_link_search_reach_entries_past_first_hundred_without_tenant_leak',
                  'test_picker_keeps_valid_old_and_assigned_items_without_exposing_disabled_or_foreign_links',
                  'test_search_and_calendar_filters_coexist_and_missing_link_migration_is_safe')),
}
FILES = {MATRIX, SPEC, *(contract[3] for contract in CONTRACTS.values())}


def sources(root: Path) -> dict[str, str]:
    result = {}
    for name in FILES:
        try:
            path = root / name
            # Test-only: ignore symbolic links and missing or undecodable sources.
            result[name] = (path.read_text(encoding='utf-8')
                            if path.is_file() and not path.is_symlink() else '')
        except (OSError, UnicodeError):
            result[name] = ''
    return result


def rows_of(document: str):
    """Reject obscured duplicates, malformed rows and incomplete five-ID sets."""
    result = {}
    for raw in document.splitlines():
        line = raw.strip()
        if not (line.startswith('|') and line.endswith('|')):
            continue
        cells = tuple(x.strip() for x in line[1:-1].split('|'))
        if not cells or cells[0] not in CONTRACTS:
            continue
        if len(cells) != 8 or cells[0] in result:
            return None
        result[cells[0]] = cells
    return result if set(result) == set(CONTRACTS) else None


def section(source: str, heading: str) -> str | None:
    matches = list(re.finditer(r'(?m)^' + re.escape(heading) + r'\s*$', source))
    if len(matches) != 1:
        return None
    rest = source[matches[0].end():]
    boundary = re.search(r'(?m)^#{1,2} ', rest)
    return rest[:boundary.start()] if boundary else rest


def asserted_method(source: str, method: str) -> bool:
    # Match the method itself, never a comment or another test's assertions.
    matches = list(re.finditer(r'(?m)^\s*public\s+function\s+'
                               + re.escape(method) + r'\s*\(', source))
    if len(matches) != 1:
        return False
    remainder = source[matches[0].end():]
    next_method = re.search(r'(?m)^\s*public\s+function\s+', remainder)
    body = remainder[:next_method.start()] if next_method else remainder
    return bool(re.search(r'(?:->assert[A-Z]|::assert[A-Z]|->expectException\()', body))


def assess(files: dict[str, str]):
    """Source evidence is never upgraded to successful CI or production."""
    evidence = {ident: {'source': UNKNOWN,
                        **{gate: UNKNOWN for gate in OPERATIONS}}
                for ident in CONTRACTS}
    rows = rows_of(files.get(MATRIX, ''))
    if rows is None:
        return evidence
    spec = files.get(SPEC, '')
    for ident, (line, heading, clauses, path, methods) in CONTRACTS.items():
        row = rows[ident]
        if (row[2] != 'NO_VERIFICABLE'
                or row[5:8] != ('NO_ACREDITADO',) * 3
                or f'GRINDFLOW-SPEC.md:L{line}' not in row[3]):
            continue
        excerpt = section(spec, heading)
        php = files.get(path, '')
        if (excerpt is None or not all(clause in excerpt for clause in clauses)
                or not php or not all(asserted_method(php, method)
                                   for method in methods)):
            continue
        evidence[ident]['source'] = SOURCE_EVIDENCE
    return evidence


def reversible_assignment(schedule: object, link: object, detach=False):
    """Toy pure model for negative coverage; NOT the PHP domain manager."""
    needed = {'tenant', 'state', 'delivered', 'link', 'clicks', 'date'}
    if (type(schedule) is not dict or set(schedule) != needed
            or schedule['state'] != 'future' or schedule['delivered'] is not False
            or not isinstance(schedule['tenant'], str) or not schedule['tenant']
            or type(schedule['clicks']) is not int or schedule['clicks'] < 0
            or type(detach) is not bool):
        return UNKNOWN
    if detach:
        return {**schedule, 'link': None}
    if (type(link) is not dict or set(link) != {'tenant', 'token', 'active'}
            or link['tenant'] != schedule['tenant'] or link['active'] is not True
            or type(link['token']) is not str or not link['token']):
        return UNKNOWN
    return {**schedule, 'link': link['token']}


def page(rows: object, tenant: str, number: int, key: str):
    """Stable, tenant-filtered pagination at 25 rows; reject ambiguous IDs."""
    if (type(rows) is not list or type(tenant) is not str or not tenant
            or type(number) is not int or not 1 <= number <= 10000
            or key not in ('scheduled_for_utc', 'created_at')):
        return UNKNOWN
    seen = set()
    for row in rows:
        if (type(row) is not dict or set(row) != {'tenant', 'id', key}
                or type(row['tenant']) is not str or not row['tenant']
                or type(row['id']) is not str or not row['id']
                or type(row[key]) is not str or not row[key]):
            return UNKNOWN
        try:
            datetime.fromisoformat(row[key].replace('Z', '+00:00'))
        except ValueError:
            return UNKNOWN
        if (row['tenant'], row['id']) in seen:
            return UNKNOWN
        seen.add((row['tenant'], row['id']))
    scoped = sorted((r for r in rows if r['tenant'] == tenant),
                    key=lambda r: (r[key], r['id']),
                    reverse=(key == 'created_at'))
    start = (number - 1) * 25
    return len(scoped), tuple(r['id'] for r in scoped[start:start + 25])


def ledger_net(events: object, tenant: str, currency: str, start: str, end: str):
    """Separate currency/beneficiary, dates inclusive, reversals subtract."""
    if (type(events) is not list or type(tenant) is not str or not tenant
            or type(currency) is not str or re.fullmatch('[A-Z]{3}', currency) is None
            or type(start) is not str or type(end) is not str
            or re.fullmatch(r'\d{4}-\d\d-\d\d', start) is None
            or re.fullmatch(r'\d{4}-\d\d-\d\d', end) is None or start > end):
        return UNKNOWN
    try:
        datetime.fromisoformat(start)
        datetime.fromisoformat(end)
    except ValueError:
        return UNKNOWN
    groups = {}
    for event in events:
        if (type(event) is not dict or set(event) !=
                {'tenant', 'currency', 'beneficiary', 'day', 'amount', 'kind'}
                or type(event['tenant']) is not str
                or type(event['currency']) is not str
                or type(event['beneficiary']) is not str
                or type(event['day']) is not str
                or type(event['amount']) is not int or event['amount'] < 0
                or event['kind'] not in ('allocation', 'reversal')):
            return UNKNOWN
        try:
            datetime.fromisoformat(event['day'])
        except ValueError:
            return UNKNOWN
        if event['tenant'] == tenant and event['currency'] == currency and start <= event['day'] <= end:
            beneficiary = event['beneficiary'] or 'unassigned'
            amount = event['amount'] * (1 if event['kind'] == 'allocation' else -1)
            groups[beneficiary] = groups.get(beneficiary, 0) + amount
    return groups


def picker(options: object, tenant: str, query: str):
    if (type(options) is not list or type(tenant) is not str or not tenant
            or type(query) is not str or not query or len(query) > 128):
        return UNKNOWN
    found = []
    seen = set()
    for entry in options:
        if (type(entry) is not dict or set(entry) != {'tenant', 'id', 'label', 'active'}
                or type(entry['tenant']) is not str or type(entry['id']) is not str
                or type(entry['label']) is not str or type(entry['active']) is not bool
                or not entry['id'] or (entry['tenant'], entry['id']) in seen):
            return UNKNOWN
        seen.add((entry['tenant'], entry['id']))
        if (entry['tenant'] == tenant and entry['active'] and
                (query.casefold() in entry['label'].casefold() or query == entry['id'])):
            found.append(entry['id'])
    return tuple(found[:100])


class BrechasGrindflow12Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.files = sources(ROOT)

    def test_contrato_principal(self):
        result = assess(self.files)
        self.assertEqual(set(result), set(CONTRACTS))
        for ident in CONTRACTS:
            with self.subTest(ident=ident):
                self.assertEqual(result[ident]['source'], SOURCE_EVIDENCE)
        base = {'tenant': 'demo-a', 'state': 'future', 'delivered': False,
                'link': None, 'clicks': 17, 'date': '2030-05-04'}
        link = {'tenant': 'demo-a', 'token': 'synthetic-link', 'active': True}
        attached = reversible_assignment(base, link)
        self.assertEqual((attached['link'], attached['clicks'], attached['date']),
                         ('synthetic-link', 17, '2030-05-04'))
        self.assertEqual(reversible_assignment(attached, None, True), base)
        schedule = [{'tenant': 'demo-a', 'id': f'{n:03}',
                     'scheduled_for_utc': '2030-01-01T00:00:00Z'} for n in range(28)]
        schedule.append({'tenant': 'demo-b', 'id': 'foreign',
                         'scheduled_for_utc': '2030-01-01T00:00:00Z'})
        self.assertEqual(page(schedule, 'demo-a', 2, 'scheduled_for_utc'),
                         (28, ('025', '026', '027')))
        events = [{'tenant': 'demo-a', 'currency': 'COP', 'beneficiary': 'b1',
                   'day': '2030-01-02', 'amount': 10, 'kind': 'allocation'},
                  {'tenant': 'demo-a', 'currency': 'COP', 'beneficiary': 'b1',
                   'day': '2030-01-03', 'amount': 30, 'kind': 'reversal'},
                  {'tenant': 'demo-b', 'currency': 'COP', 'beneficiary': 'b1',
                   'day': '2030-01-03', 'amount': 40, 'kind': 'allocation'},
                  {'tenant': 'demo-a', 'currency': 'USD', 'beneficiary': 'b1',
                   'day': '2030-01-03', 'amount': 99, 'kind': 'allocation'}]
        self.assertEqual(ledger_net(events, 'demo-a', 'COP', '2030-01-02', '2030-01-03'),
                         {'b1': -20})
        links = [{'tenant': 'demo-a', 'id': f'link-{n:03}',
                  'created_at': f'2030-01-{n % 28 + 1:02}T12:00:00Z'} for n in range(130)]
        total, last_page = page(links, 'demo-a', 6, 'created_at')
        self.assertEqual(total, 130)
        self.assertEqual(len(last_page), 5)
        options = [{'tenant': 'demo-a', 'id': f'media-{n}',
                    'label': f'asset-{n}', 'active': True} for n in range(125)]
        options.append({'tenant': 'demo-b', 'id': 'media-125',
                        'label': 'asset-124', 'active': True})
        self.assertEqual(picker(options, 'demo-a', 'asset-124'), ('media-124',))

    def test_falla_cerrado(self):
        baseline = assess(self.files)
        for ident, (anchor, heading, tokens, php, methods) in CONTRACTS.items():
            self.assertEqual(baseline[ident]['source'], SOURCE_EVIDENCE)
            line = next(x for x in self.files[MATRIX].splitlines()
                        if x.strip().startswith(f'| {ident} |'))
            for variant in (line, '  ' + line, '\t' + line,
                            line.replace('| ', '|', 1)):
                changed = dict(self.files)
                changed[MATRIX] += '\n' + variant
                with self.subTest(ident=ident, variant=variant[:12]):
                    self.assertEqual(assess(changed)[ident]['source'], UNKNOWN)
            tampered = dict(self.files)
            tampered[MATRIX] = self.files[MATRIX].replace(line, line.replace('NO_VERIFICABLE', 'CUBIERTO', 1), 1)
            self.assertEqual(assess(tampered)[ident]['source'], UNKNOWN)
            block = section(self.files[SPEC], heading)
            self.assertIsNotNone(block)
            tampered = dict(self.files)
            tampered[SPEC] = self.files[SPEC].replace(block, block.replace(tokens[0], 'REMOVED', 1), 1)
            self.assertEqual(assess(tampered)[ident]['source'], UNKNOWN)
            for method in methods:
                tampered = dict(self.files)
                tampered[php] = self.files[php].replace('public function ' + method + '(', 'public function removed_method(', 1)
                with self.subTest(ident=ident, method=method):
                    self.assertEqual(assess(tampered)[ident]['source'], UNKNOWN)
            # A retained method name without any PHPUnit assertions is also
            # insufficient. Strip only the first selected method's assertions.
            method = methods[0]
            start = re.search(r'(?m)^\s*public\s+function\s+' + re.escape(method) + r'\s*\(', self.files[php])
            self.assertIsNotNone(start)
            following = re.search(r'(?m)^\s*public\s+function\s+', self.files[php][start.end():])
            finish = start.end() + following.start() if following else len(self.files[php])
            body = self.files[php][start.start():finish]
            stripped = re.sub(r'(->|::)assert[A-Z]', r'\1noopA', body)
            stripped = stripped.replace('->expectException(', '->noopException(')
            self.assertNotEqual(body, stripped)
            tampered = dict(self.files)
            tampered[php] = self.files[php][:start.start()] + stripped + self.files[php][finish:]
            self.assertEqual(assess(tampered)[ident]['source'], UNKNOWN)
        schedule = {'tenant': 'demo-a', 'state': 'future', 'delivered': False,
                    'link': None, 'clicks': 0, 'date': '2030-02-01'}
        self.assertEqual(reversible_assignment(schedule, {'tenant': 'demo-b', 'token': 'x', 'active': True}), UNKNOWN)
        self.assertEqual(reversible_assignment(schedule, {'tenant': 'demo-a', 'token': 'x', 'active': False}), UNKNOWN)
        self.assertEqual(reversible_assignment({**schedule, 'delivered': True}, None, True), UNKNOWN)
        self.assertEqual(page([{'tenant': 'demo-a', 'id': 'x', 'scheduled_for_utc': '2030-01-01'}] * 2,
                              'demo-a', 1, 'scheduled_for_utc'), UNKNOWN)
        self.assertEqual(page([], 'demo-a', True, 'scheduled_for_utc'), UNKNOWN)
        self.assertEqual(ledger_net([], 'demo-a', 'COP', '2030-01-03', '2030-01-01'), UNKNOWN)
        self.assertEqual(ledger_net([{'tenant': 'demo-a', 'currency': 'COP', 'beneficiary': '',
                                      'day': '2030-01-02', 'amount': True, 'kind': 'allocation'}],
                                     'demo-a', 'COP', '2030-01-01', '2030-01-03'), UNKNOWN)
        self.assertEqual(picker([{'tenant': 'demo-a', 'id': 'a', 'label': 'x', 'active': 'true'}],
                                'demo-a', 'x'), UNKNOWN)
        self.assertEqual(ledger_net([], 'demo-a', True, '2030-01-01', '2030-01-03'), UNKNOWN)
        duplicated = [{'tenant': 'demo-a', 'id': 'same', 'label': 'x', 'active': True}] * 2
        self.assertEqual(picker(duplicated, 'demo-a', 'x'), UNKNOWN)

    def test_sin_datos_reales(self):
        # Source inspection is local and a blocked socket cannot change its result.
        with patch('socket.socket', side_effect=AssertionError('red prohibida')):
            result = assess(sources(ROOT))
        self.assertTrue(all(r['source'] == SOURCE_EVIDENCE for r in result.values()))
        self.assertEqual(set(FILES), {MATRIX, SPEC, *[c[3] for c in CONTRACTS.values()]})
        self.assertFalse(any('customer' in key or 'secret' in key or 'password' in key
                             for record in result.values() for key in record))

    def test_estados_unknown_explicitos(self):
        result = assess(self.files)
        for ident, states in result.items():
            with self.subTest(ident=ident):
                self.assertEqual(states['source'], SOURCE_EVIDENCE)
                self.assertEqual(set(states), {'source', *OPERATIONS})
                for gate in OPERATIONS:
                    self.assertEqual(states[gate], UNKNOWN)
        for source in FILES:
            broken = dict(self.files)
            broken[source] = ''
            values = assess(broken)
            self.assertTrue(any(x['source'] == UNKNOWN for x in values.values()))


if __name__ == '__main__':
    unittest.main()
