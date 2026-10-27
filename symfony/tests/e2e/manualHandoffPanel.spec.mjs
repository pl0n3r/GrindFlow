import { test, expect } from '@playwright/test';

// All endpoints are mocked in preview; synthetic UUIDs, no credentials or writes.
const D1 = '00000000-0000-7000-8000-000000000011';
const D2 = '00000000-0000-7000-8000-000000000022';
const Q1 = '00000000-0000-7000-8000-000000000031';
const Q2 = '00000000-0000-7000-8000-000000000032';
const A1 = '00000000-0000-7000-8000-000000000041';

for (const scenario of ['lista', 'vacio', '403', 'red', 'desconocido']) {
  test('Entregas manuales GET-only: ' + scenario, async ({ page }) => {
    const requests = [];
    const unsafe = [];
    page.on('request', (request) => {
      const url = new URL(request.url());
      if (url.pathname.startsWith('/api/admin/') &&
        !['GET', 'HEAD'].includes(request.method())) {
        unsafe.push(request.method() + ' ' + url.pathname);
      }
      if (url.pathname === '/api/admin/manual-handoff-queue' ||
        url.pathname === '/api/admin/manual-destinations') {
        requests.push({ pathname: url.pathname, method: request.method() });
      }
    });

    await page.goto('/preview');
    const script = await page.locator('script[type="module"]').first().getAttribute('src');
    expect(script).toBeTruthy();

    await page.route('**/api/admin/context', (route) => route.fulfill({
      status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
        user: { display_name: 'Persona sintética' },
        organization: { id: '00000000-0000-7000-8000-000000000088',
          name: 'Organización ficticia', role: 'model' },
        permissions: {
          workspace_view: true, organization_manage: false, content_prepare: false,
          manual_handoff_manage: false,
        },
        vault_upload_csrf: null, vault_manage_csrf: null,
      } }),
    }));
    await page.route('**/api/admin/library/collections*', (route) => route.fulfill({
      status: 200, contentType: 'application/json',
      body: JSON.stringify({ data: { collections: [], has_more: false, next_cursor: null } }),
    }));
    await page.route('**/api/admin/manual-handoff-queue', (route) => {
      expect(route.request().method()).toBe('GET');
      if (scenario === 'red') return route.abort('failed');
      if (scenario === '403') return route.fulfill({
        status: 403, contentType: 'application/json',
        body: JSON.stringify({ error: { code: 'organization_access_changed' } }),
      });
      const data = scenario === 'desconocido'
        ? { ready: false, items: [], total: 0, limit: 30, provider_calls: false }
        : { ready: true,
            items: scenario === 'vacio' ? [] : [
              { draft_id: Q1, asset_id: A1, asset_name: 'Recurso sintético',
                scheduled_at_utc: '2026-10-01T10:00:00Z', status: 'prepared',
                due: true, destination: { id: D1, label: 'Entrega ficticia A', active: true } },
              { draft_id: Q2, asset_id: A1, asset_name: 'Recurso ficticio B',
                scheduled_at_utc: '2026-10-02T10:00:00Z', status: 'failed',
                due: false, destination: null },
            ],
            total: scenario === 'vacio' ? 0 : 2, limit: 30, can_manage: false,
            provider_calls: false };
      return route.fulfill({ status: 200, contentType: 'application/json',
        body: JSON.stringify({ data }) });
    });
    await page.route('**/api/admin/manual-destinations', (route) => {
      expect(route.request().method()).toBe('GET');
      if (scenario === 'red') return route.abort('failed');
      if (scenario === '403') return route.fulfill({
        status: 403, contentType: 'application/json',
        body: JSON.stringify({ error: { code: 'organization_access_changed' } }),
      });
      const data = scenario === 'desconocido'
        ? { ready: false, destinations: [], total: 0, limit: 100, provider_calls: false }
        : { ready: true, destinations: scenario === 'vacio' ? [] : [
          { id: D1, label: 'Entrega ficticia A', active: true },
          { id: D2, label: 'Entrega ficticia B', active: false },
        ], total: scenario === 'vacio' ? 0 : 2, limit: 100, provider_calls: false };
      return route.fulfill({ status: 200, contentType: 'application/json',
        body: JSON.stringify({ data }) });
    });

    await page.evaluate(() => {
      document.body.innerHTML = '<div class="admin-page"><main id="contenido" tabindex="-1">' +
        '<div id="grindflow-admin"></div></main></div>';
    });
    await page.addScriptTag({ url: script + '?manual-handoff-e2e=' + scenario, type: 'module' });
    const nav = page.getByRole('navigation', { name: 'Navegación administrativa' });
    const link = nav.getByRole('link', { name: /Entregas manuales/ });
    await expect(link).toBeVisible();
    await link.click();
    await expect(link).toHaveAttribute('aria-current', 'location');
    const section = page.locator('#entregas-manuales');
    await expect(section.getByRole('heading', { name: 'Entregas manuales' })).toBeVisible();
    // Avoid duplicating the S3 planner heading "Destinos manuales".
    await expect(section.getByRole('heading', { name: 'Catálogo de destinos de entrega' }))
      .toBeVisible();

    if (scenario === 'lista') {
      await expect(section.getByRole('table', { name: /Entregas manuales pendientes/ })
        .locator('tbody tr')).toHaveCount(2);
      await expect(section.getByRole('table', { name: /Entregas manuales pendientes/ }))
        .toContainText('Recurso sintético');
      await expect(section.getByRole('table', { name: /Entregas manuales pendientes/ }))
        .toContainText('UNKNOWN');
      await expect(section.getByRole('table', { name: /Destinos registrados/ })
        .locator('tbody tr')).toHaveCount(2);
      await expect(section.getByRole('table', { name: /Destinos registrados/ }))
        .toContainText('Deshabilitado');
    } else if (scenario === 'vacio') {
      await expect(section.getByRole('status').getByText('No hay entregas manuales pendientes.'))
        .toBeVisible();
      await expect(section.getByRole('status').getByText('No hay destinos manuales registrados.'))
        .toBeVisible();
    } else if (scenario === 'desconocido') {
      await expect(section.getByRole('status').getByText('UNKNOWN: cola no verificable.'))
        .toBeVisible();
      await expect(section.getByRole('status').getByText('UNKNOWN: destinos no verificables.'))
        .toBeVisible();
      await expect(section.getByRole('table')).toHaveCount(0);
    } else {
      await expect(section.getByRole('alert')).toHaveCount(2);
      await expect(section.getByRole('alert').first())
        .toContainText(scenario === '403' ? 'Acceso denegado' : 'Error de red');
    }
    await expect.poll(() => new Set(requests.map((r) => r.pathname)).size).toBe(2);
    expect(requests.every((r) => r.method === 'GET')).toBe(true);
    expect(unsafe).toEqual([]);
  });
}


for (const width of [360, 820]) {
  test('Tablas de entregas manuales accesibles por teclado a ' + width + 'px',
    async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      const requested = [];
      page.on('request', (request) => {
        const url = new URL(request.url());
        if (url.pathname.startsWith('/api/admin/')) {
          requested.push({ method: request.method(), path: url.pathname });
        }
      });

      await page.goto('/preview');
      const script = await page.locator('script[type="module"]').first().getAttribute('src');
      expect(script).toBeTruthy();
      await page.route('**/api/admin/context', (route) => route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({ data: {
          user: { display_name: 'Persona sintética' },
          organization: { id: '00000000-0000-7000-8000-000000000088',
            name: 'Organización ficticia', role: 'model' },
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
        body: JSON.stringify({ data: { ready: true, provider_calls: false, limit: 30,
          total: 1, items: [{ draft_id: Q1, asset_id: A1,
            asset_name: 'Recurso ficticio de la organización',
            scheduled_at_utc: '2026-10-01T10:00:00Z',
            status: 'prepared', due: false,
            destination: { id: D1, label: 'Entrega ficticia A', active: true } }] } }),
      }));
      await page.route('**/api/admin/manual-destinations', (route) => route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({ data: { ready: true, provider_calls: false, limit: 100,
          total: 1, destinations: [{ id: D1, label: 'Entrega ficticia A', active: true }] } }),
      }));

      await page.evaluate(() => {
        document.body.innerHTML = '<div class="admin-page"><main id="contenido" tabindex="-1">' +
          '<div id="grindflow-admin"></div></main></div>';
      });
      await page.addScriptTag({ url: script + '?manual-handoff-a11y=' + width, type: 'module' });
      const nav = page.getByRole('navigation', { name: 'Navegación administrativa' });
      await nav.getByRole('link', { name: /Entregas manuales/ }).click();
      const section = page.locator('#entregas-manuales');
      const wrappers = section.locator('.manual-handoff-scroll-region');
      await expect(wrappers).toHaveCount(2);
      const labels = [
        'Desplazamiento horizontal de entregas manuales',
        'Desplazamiento horizontal de destinos manuales',
      ];

      for (let index = 0; index < 2; index += 1) {
        const wrapper = wrappers.nth(index);
        const overflowing = await wrapper.evaluate(
          (element) => element.scrollWidth > element.clientWidth,
        );
        if (width === 360) expect(overflowing).toBe(true);
        await expect.poll(() => wrapper.getAttribute('tabindex'))
          .toBe(overflowing ? '0' : null);
        await expect(wrapper).toHaveAttribute(
          'class', /manual-handoff-scroll-region/,
        );
        if (overflowing) {
          await expect(wrapper).toHaveAttribute('role', 'region');
          await expect(wrapper).toHaveAttribute('aria-label', labels[index]);
        } else {
          await expect(wrapper).not.toHaveAttribute('role');
          await expect(wrapper).not.toHaveAttribute('aria-label');
        }

        // Place synthetic neighbors to verify real keyboard order without
        // relying on the number of unrelated admin navigation controls.
        const beforeId = 'keyboard-before-' + index;
        const afterId = 'keyboard-after-' + index;
        await wrapper.evaluate((element, ids) => {
          const before = document.createElement('button');
          before.id = ids.before;
          before.textContent = 'Antes de tabla';
          const after = document.createElement('button');
          after.id = ids.after;
          after.textContent = 'Después de tabla';
          element.before(before);
          element.after(after);
          before.focus();
        }, { before: beforeId, after: afterId });
        await page.keyboard.press('Tab');
        if (overflowing) {
          await expect(wrapper).toBeFocused();
          const visibleOutline = await wrapper.evaluate(
            (element) => getComputedStyle(element).outlineStyle,
          );
          expect(visibleOutline).not.toBe('none');
          const before = await wrapper.evaluate((element) => element.scrollLeft);
          await page.keyboard.press('ArrowRight');
          await expect.poll(() => wrapper.evaluate((element) => element.scrollLeft))
            .toBeGreaterThan(before);
          await page.keyboard.press('Shift+Tab');
          await expect(page.locator('#' + beforeId)).toBeFocused();
        } else {
          await expect(page.locator('#' + afterId)).toBeFocused();
          await page.keyboard.press('Shift+Tab');
          await expect(page.locator('#' + beforeId)).toBeFocused();
        }
      }
      expect(await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      )).toBe(true);
      const API = ['/api/admin/manual-handoff-queue', '/api/admin/manual-destinations'];
      expect(API.every((path) => requested.some(
        (request) => request.path === path && request.method === 'GET',
      ))).toBe(true);
      expect(requested.filter((request) => request.method !== 'GET'
        && request.method !== 'HEAD')).toEqual([]);
    });
}
