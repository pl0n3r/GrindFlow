import { test, expect } from '@playwright/test';

// Fixtures sintéticos. El contrato del panel es exclusivamente GET.
const ID = '00000000-0000-7000-8000-000000000099';
const rule = {
  timezone: 'America/Bogota', weekdays: ['mon', 'wed'], local_time: '09:30',
  max_per_day: 2, mode: 'review_only', updated_at: '2026-10-01 10:00:00',
};
const preview = {
  rule, slots: [{ scheduled_at_utc: '2026-10-27T14:30:00Z' }],
  assets: [{ id: ID, name: 'Recurso sintético', eligible: true, blocking_reasons: [] }],
  visible: 1, total_active_assets: 1, limit: 30, can_publish: false, mode: 'review_only',
};
const automation = {
  ready: true, provider_calls: false,
  rule: { state: 'paused', local_times: ['09:30'], destination_id: null,
    source: 'eligible_assets', provider: 'facebook_page', max_per_day: 2,
    timezone: 'America/Bogota' },
};

for (const scenario of ['lista', 'vacio', '403', 'red', 'unknown']) {
  test('Reglas semanales GET-only: ' + scenario, async ({ page }) => {
    const unsafe = [];
    const queries = [];
    page.on('request', (request) => {
      const url = new URL(request.url());
      if (url.pathname.startsWith('/api/admin/rules/') &&
        !['GET', 'HEAD'].includes(request.method())) {
        unsafe.push(request.method() + ' ' + url.pathname);
      }
      if (['/api/admin/rules/weekly', '/api/admin/rules/weekly/preview',
        '/api/admin/rules/automation'].includes(url.pathname)) {
        queries.push({ path: url.pathname, method: request.method() });
      }
    });

    await page.goto('/preview');
    const script = await page.locator('script[type="module"]').first().getAttribute('src');
    expect(script).toBeTruthy();
    await page.route('**/api/admin/context', (route) => route.fulfill({
      status: 200, contentType: 'application/json',
      body: JSON.stringify({ data: {
        user: { display_name: 'Persona ficticia' },
        organization: { id: ID, name: 'Organización ficticia', role: 'model' },
        permissions: { workspace_view: true, organization_manage: false,
          content_prepare: false, manual_handoff_manage: false },
        vault_upload_csrf: null, vault_manage_csrf: null,
      } }),
    }));
    await page.route('**/api/admin/library/collections*', (route) => route.fulfill({
      status: 200, contentType: 'application/json',
      body: JSON.stringify({ data: { collections: [], has_more: false, next_cursor: null } }),
    }));
    await page.route('**/api/admin/manual-handoff-queue', (route) => route.fulfill({
      status: 200, contentType: 'application/json',
      body: JSON.stringify({ data: { ready: true, items: [], total: 0, limit: 30, provider_calls: false } }),
    }));
    await page.route('**/api/admin/manual-destinations', (route) => route.fulfill({
      status: 200, contentType: 'application/json',
      body: JSON.stringify({ data: { ready: true, destinations: [], total: 0, limit: 30, provider_calls: false } }),
    }));

    await page.route('**/api/admin/rules/weekly', (route) => {
      expect(route.request().method()).toBe('GET');
      if (scenario === 'red') return route.abort('failed');
      if (scenario === '403') return route.fulfill({
        status: 403, contentType: 'application/json',
        body: JSON.stringify({ error: { code: 'organization_access_changed' } }),
      });
      return route.fulfill({ status: 200, contentType: 'application/json',
        body: JSON.stringify({ data: {
          rule: scenario === 'vacio' ? null : scenario === 'unknown' ? { mode: 'UNKNOWN' } : rule,
        } }),
      });
    });
    await page.route('**/api/admin/rules/weekly/preview', (route) => {
      expect(route.request().method()).toBe('GET');
      return route.fulfill({ status: 200, contentType: 'application/json',
        body: JSON.stringify({ data: scenario === 'vacio'
          ? { ...preview, rule: null, slots: [], assets: [], visible: 0,
            total_active_assets: 0 }
          : scenario === 'unknown' ? { ...preview, can_publish: true } : preview }),
      });
    });
    await page.route('**/api/admin/rules/automation', (route) => {
      expect(route.request().method()).toBe('GET');
      return route.fulfill({ status: 200, contentType: 'application/json',
        body: JSON.stringify({ data: scenario === 'vacio'
          ? { ready: true, rule: null, provider_calls: false }
          : scenario === 'unknown' ? { ready: false, rule: null, provider_calls: false }
            : automation }),
      });
    });

    await page.evaluate(() => {
      document.body.innerHTML = '<div class="admin-page"><main id="contenido" tabindex="-1">' +
        '<div id="grindflow-admin"></div></main></div>';
    });
    await page.addScriptTag({ url: script + '?rules-panel-e2e=' + scenario, type: 'module' });
    const nav = page.getByRole('navigation', { name: 'Navegación administrativa' });
    const link = nav.getByRole('link', { name: /Reglas/ });
    await expect(link).toBeVisible();
    await link.click();
    await expect(link).toHaveAttribute('aria-current', 'location');
    const section = page.locator('#reglas');
    await expect(section.getByRole('heading', { name: 'Reglas semanales y automatización' })).toBeVisible();
    await expect.poll(() => queries.length).toBeGreaterThanOrEqual(3);
    if (scenario === 'lista') {
      await expect(page.getByText('Miércoles')).toBeVisible();
      await expect(page.getByText('Recurso sintético')).toBeVisible();
      await expect(page.getByText('Pausada')).toBeVisible();
    } else if (scenario === 'vacio') {
      await expect(page.getByText('No hay una regla semanal configurada.')).toBeVisible();
      await expect(page.getByText('No hay horarios propuestos.')).toBeVisible();
      await expect(page.getByText('No hay una automatización configurada.')).toBeVisible();
    } else if (scenario === '403') {
      await expect(section.getByRole('alert')).toContainText('401/403/409');
    } else if (scenario === 'red') {
      await expect(section.getByRole('alert')).toContainText('Error de red');
    } else {
      await expect(page.getByText(/UNKNOWN: regla semanal no verificable/)).toBeVisible();
      await expect(page.getByText(/UNKNOWN: vista previa no verificable/)).toBeVisible();
      await expect(page.getByText(/UNKNOWN: automatización no verificable/)).toBeVisible();
    }
    expect(unsafe).toEqual([]);
    expect(queries.map((q) => q.path).sort()).toEqual([
      '/api/admin/rules/automation', '/api/admin/rules/weekly',
      '/api/admin/rules/weekly/preview',
    ]);
    expect(queries.every((q) => q.method === 'GET')).toBe(true);
  });
}
