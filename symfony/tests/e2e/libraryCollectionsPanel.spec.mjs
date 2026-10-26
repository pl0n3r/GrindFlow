import { test, expect } from '@playwright/test';

// Only synthetic responses; never authenticates against a real tenant.
const C1 = '00000000-0000-7000-8000-000000000011';
const C2 = '00000000-0000-7000-8000-000000000022';
const A1 = '00000000-0000-7000-8000-000000000031';
const A2 = '00000000-0000-7000-8000-000000000032';
const A3 = '00000000-0000-7000-8000-000000000033';

for (const scenario of ['lista', 'vacio', '403', 'red', 'incompleto']) {
  test('Colecciones admin GET-only: ' + scenario, async ({ page }) => {
    const unsafe = [];
    page.on('request', (request) => {
      if (new URL(request.url()).pathname.startsWith('/api/admin/')
        && !['GET', 'HEAD'].includes(request.method())) {
        unsafe.push(request.method() + ' ' + request.url());
      }
    });
    await page.goto('/preview');
    const script = await page.locator('script[type="module"]').first().getAttribute('src');
    expect(script).toBeTruthy();

    await page.route('**/api/admin/context', (route) => route.fulfill({
      status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
        user: { display_name: 'Persona sintética' },
        organization: { id: '00000000-0000-7000-8000-000000000088', name: 'Organización sintética', role: 'model' },
        permissions: { workspace_view: true, organization_manage: false, content_prepare: false },
        weekly_rule_csrf: null, vault_upload_csrf: null, vault_manage_csrf: null,
      } }),
    }));
    await page.route('**/api/admin/library/**', async (route) => {
      expect(route.request().method()).toBe('GET');
      const path = new URL(route.request().url()).pathname;
      if (path.endsWith('/assets')) {
        const isFirst = path.includes(C1);
        const next = new URL(route.request().url()).searchParams.has('after');
        const assets = isFirst ? (next ? [A3] : [A1, A2]) : [];
        return route.fulfill({ status: 200, contentType: 'application/json',
          body: JSON.stringify({ data: { status: 'ok', assets,
            has_more: isFirst && !next, next_cursor: isFirst && !next ? A2 : null } }) });
      }
      if (scenario === 'red') return route.abort('failed');
      if (scenario === '403') return route.fulfill({
        status: 403, contentType: 'application/json',
        body: JSON.stringify({ error: { code: 'organization_access_changed' } }),
      });
      const data = scenario === 'vacio'
        ? { collections: [], has_more: false, next_cursor: null }
        : scenario === 'incompleto'
          ? { collections: [C1], has_more: true, next_cursor: null }
          : { collections: [C1, C2], has_more: false, next_cursor: null };
      return route.fulfill({ status: 200, contentType: 'application/json',
        body: JSON.stringify({ data }) });
    });

    await page.evaluate(() => {
      document.body.innerHTML = '<div class="admin-page"><main id="contenido" tabindex="-1"><div id="grindflow-admin"></div></main></div>';
    });
    await page.addScriptTag({ url: script + '?collections-e2e=' + scenario, type: 'module' });
    const nav = page.getByRole('navigation', { name: 'Navegación administrativa' });
    const link = nav.getByRole('link', { name: /Colecciones/ });
    await expect(link).toBeVisible();
    await link.click();
    await expect(link).toHaveAttribute('aria-current', 'location');
    await expect(page.getByRole('heading', { name: 'Colecciones de la biblioteca' })).toBeVisible();
    if (scenario === 'lista') {
      await expect(page.getByRole('table', { name: /Colecciones de la organización/ })).toContainText(C1);
      await expect(page.getByRole('table', { name: /Colecciones de la organización/ }).locator('tbody tr')).toHaveCount(2);
      await expect(page.getByRole('table', { name: /Colecciones de la organización/ }).locator('tbody tr').first()).toContainText('3');
      await expect(page.getByRole('table', { name: /Colecciones de la organización/ }).locator('tbody tr').last()).toContainText('0');
    } else if (scenario === 'vacio') {
      await expect(page.getByRole('status').getByText(/No hay colecciones/)).toBeVisible();
    } else if (scenario === 'incompleto') {
      await expect(page.getByRole('status').getByText(/UNKNOWN: hay más colecciones/)).toBeVisible();
    } else {
      await expect(page.getByRole('alert')).toContainText(scenario === '403' ? 'Acceso denegado' : 'No se pudo consultar');
    }
    expect(unsafe).toEqual([]);
  });
}
