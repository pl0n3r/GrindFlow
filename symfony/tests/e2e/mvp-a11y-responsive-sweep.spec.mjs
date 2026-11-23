import { test, expect } from '@playwright/test';

// #1048: disposable Symfony + the actual compiled React workspace, no tenant
// accounts, provider traffic, production endpoints, or browser-side mutations.
// This exact navigation inventory deliberately fails on a newly shipped MVP
// section until its responsive/keyboard/empty/error contract is extended.
const VIEWPORTS = [390, 1440];
const PREVIEW_ITEMS = ['Biblioteca', 'Programación', 'Tráfico'];
const MVP_HASHES = [
  '/admin', '#biblioteca', '#colecciones', '#programacion',
  '#entregas-manuales', '#salud-destinos', '#reglas', '#piloto',
];
const TENANT_ID = '00000000-0000-7000-8000-000000000088';

function mondayUtc() {
  const date = new Date();
  const monday = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(),
    date.getUTCDate() - ((date.getUTCDay() + 6) % 7)));
  return monday.toISOString().slice(0, 10);
}
function nextDate(week, days) {
  const date = new Date(week + 'T00:00:00Z');
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}
function emptyPilotSummary(week) {
  return {
    ready: true, week_start_utc: week, week_end_exclusive_utc: nextDate(week, 7),
    days: Array.from({ length: 7 }, (_, offset) => ({
      date_utc: nextDate(week, offset),
      prepared_attempts: 0, completed_reports: 0, failed_attempts: 0,
    })),
    totals: { prepared_attempts: 0, completed_reports: 0, failed_attempts: 0 },
    traffic: { ready: false, clicks: null },
    external_publications_verified: null, provider_calls: false,
    mvp: {
      content: { ready: false, available_assets: null, original_blobs: null,
        used_bytes: null, eligibility_ready: false, ready_to_schedule: null },
      upcoming: { ready: true, total: 0, items: [] },
      deliveries: { ready: true, counts: { published: 0 }, recent: [] },
      traffic: { ready: false, clicks: null },
    },
  };
}
function json(route, data, status = 200) {
  return route.fulfill({
    status, contentType: 'application/json', body: JSON.stringify(data),
  });
}
function contextData() {
  return {
    user: { display_name: 'Persona sintética QA' },
    organization: { id: TENANT_ID, name: 'Estudio sintético', role: 'model' },
    organization_name_csrf: null, profile_name_csrf: null,
    profile_password_csrf: null, weekly_rule_csrf: null,
    platform_plan_limits_manage: false,
    vault_upload_csrf: null, vault_manage_csrf: null,
    permissions: { workspace_view: true, organization_manage: false,
      content_prepare: false, content_review: true, content_review_decide: false,
      distribution_authorize: false, manual_handoff_manage: false },
  };
}
async function admin(page, { failContext = false } = {}) {
  await page.goto('/preview');
  const asset = await page.locator('script[type="module"]').first().getAttribute('src');
  expect(asset).toBeTruthy();
  const requests = [];
  await page.route('**/api/admin/**', (route) => {
    const request = route.request();
    const url = new URL(request.url());
    requests.push({ path: url.pathname, method: request.method() });
    expect(['GET', 'HEAD'], 'Synthetic sweep must not mutate APIs').toContain(request.method());
    if (url.pathname === '/api/admin/context') {
      return failContext
        ? json(route, { error: { message: 'Acceso sintético expirado.' } }, 401)
        : json(route, { data: contextData() });
    }
    if (url.pathname === '/api/admin/library/collections') {
      return json(route, { data: { collections: [], has_more: false, next_cursor: null } });
    }
    if (url.pathname === '/api/admin/manual-handoff-queue') {
      return json(route, { data: { ready: true, items: [], total: 0,
        limit: 30, can_manage: false, provider_calls: false } });
    }
    if (url.pathname === '/api/admin/manual-destinations') {
      return json(route, { data: { ready: true, destinations: [],
        total: 0, limit: 30, provider_calls: false } });
    }
    if (url.pathname === '/api/admin/rules/weekly') {
      return json(route, { data: { rule: null } });
    }
    if (url.pathname === '/api/admin/rules/weekly/preview') {
      return json(route, { data: { rule: null, slots: [], assets: [],
        visible: 0, total_active_assets: 0, limit: 30,
        can_publish: false, mode: 'review_only' } });
    }
    if (url.pathname === '/api/admin/rules/automation') {
      return json(route, { data: { ready: true, rule: null, provider_calls: false } });
    }
    if (url.pathname === '/api/admin/pilot/weekly-summary') {
      return json(route, { data: emptyPilotSummary(
        url.searchParams.get('week') ?? mondayUtc()) });
    }
    // Only this synthetic browser is allowed to see mocked failures.
    return json(route, { error: { message: 'Servicio sintético no disponible.' } }, 503);
  });
  await page.evaluate(() => {
    document.body.innerHTML = '<a class="skip-link" href="#contenido">Saltar al contenido</a>' +
      '<div class="admin-page"><main id="contenido" tabindex="-1">' +
      '<div id="grindflow-admin"></div></main></div>';
  });
  await page.addScriptTag({ url: asset + '?mvp-a11y-responsive-1048=1', type: 'module' });
  if (!failContext) {
    await expect(page.getByRole('navigation', { name: 'Navegación administrativa' }))
      .toBeVisible();
  }
  return requests;
}
async function noDocumentOverflow(page, width, label) {
  const layout = await page.evaluate(() => ({
    width: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    scrollX: window.scrollX,
  }));
  expect(layout.scrollWidth, label + ': ' + JSON.stringify(layout))
    .toBeLessThanOrEqual(width + 1);
}
async function tabTo(page, target, cap = 80) {
  for (let attempt = 0; attempt < cap; attempt += 1) {
    await page.keyboard.press('Tab');
    if (await target.evaluate((node) => document.activeElement === node)) return;
  }
  throw new Error('Control no alcanzable por Tab en ' + cap + ' pasos');
}
async function hasKeyboardFocus(target) {
  const info = await target.evaluate((node) => ({
    focusVisible: node.matches(':focus-visible'),
    outline: getComputedStyle(node).outlineStyle,
    width: parseFloat(getComputedStyle(node).outlineWidth),
  }));
  expect(info.focusVisible, 'Teclado debe activar :focus-visible').toBe(true);
  expect(info.outline).not.toBe('none');
  expect(info.width).toBeGreaterThan(0);
}
async function capture(page, testInfo, name) {
  const file = testInfo.outputPath(name + '.png');
  await page.screenshot({ path: file, fullPage: true });
  await testInfo.attach(name + '.png', { path: file, contentType: 'image/png' });
}

for (const width of VIEWPORTS) {
  test('MVP público: rutas, teclado y conceptos responsive en ' + width + 'px',
    async ({ page }, testInfo) => {
      await page.setViewportSize({ width, height: 900 });
      for (const path of ['/', '/preview', '/login']) {
        await page.goto(path);
        await page.keyboard.press('Tab');
        const skip = page.getByRole('link', { name: 'Saltar al contenido' });
        await expect(skip).toBeFocused();
        await hasKeyboardFocus(skip);
        await page.keyboard.press('Enter');
        await expect(page.locator('main#contenido')).toBeFocused();
        await noDocumentOverflow(page, width, path);
      }
      await page.goto('/preview');
      const nav = page.getByRole('navigation', { name: 'Explorador de secciones' });
      expect(await nav.getByRole('button').allTextContents().then(
        (labels) => labels.map((name) => name.replace(/^\s*\d+\s*/, '').trim()
          .replace(/\s+/g, ' '))))
        .toEqual(PREVIEW_ITEMS);
      await capture(page, testInfo, 'before-preview-biblioteca-' + width);
      for (const [index, section] of PREVIEW_ITEMS.entries()) {
        const button = nav.getByRole('button', { name: new RegExp(section) });
        if (index === 0) {
          await tabTo(page, button);
        } else {
          await page.keyboard.press('Tab');
          await expect(button).toBeFocused();
        }
        await hasKeyboardFocus(button);
        await page.keyboard.press(index === 1 ? 'Space' : 'Enter');
        await expect(button).toHaveAttribute('aria-pressed', 'true');
        await expect(page.locator('.preview-board'))
          .toHaveAttribute('aria-label', 'Concepto de ' + section);
        await noDocumentOverflow(page, width, section);
      }
      await capture(page, testInfo, 'after-preview-trafico-' + width);

      await page.goto('/login');
      await expect(page.getByLabel('Correo electrónico')).toBeVisible();
      await expect(page.getByLabel('Contraseña')).toBeVisible();
      await page.getByLabel('Correo electrónico').fill('synthetic@example.test');
      await page.getByLabel('Contraseña').fill('clave-equivocada-sintetica');
      await page.getByRole('button', { name: 'Entrar' }).click();
      await expect(page.getByRole('alert')).toContainText('No se pudo iniciar sesión');
      await noDocumentOverflow(page, width, 'login-error');
    });

  test('MVP privado: inventario, estados vacíos y error sin overflow en ' +
    width + 'px', async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    const reads = await admin(page);
    const nav = page.getByRole('navigation', { name: 'Navegación administrativa' });
    const hrefs = await nav.locator('a.workspace-nav-item').evaluateAll((nodes) =>
      nodes.map((node) => node.getAttribute('href')));
    expect(hrefs, 'Cada sección MVP nueva requiere un caso E2E')
      .toEqual(MVP_HASHES);
    await expect(page.getByRole('heading', { name: /Tu espacio/ })).toBeVisible();
    await noDocumentOverflow(page, width, 'resumen');

    // The page-level /admin destination is the first of the eight links.
    // Verify its real keyboard path and visible focus without leaving this
    // synthetic fixture; section anchors below are activated with Enter.
    const summaryLink = nav.locator('a.workspace-nav-item[href="/admin"]');
    await expect(summaryLink).toHaveCount(1);
    await tabTo(page, summaryLink);
    await hasKeyboardFocus(summaryLink);
    await expect(summaryLink).toHaveAttribute('aria-current', 'page');

    for (const hash of MVP_HASHES.slice(1)) {
      const link = nav.locator('a.workspace-nav-item[href="' + hash + '"]');
      await expect(link).toHaveCount(1);
      await tabTo(page, link);
      await hasKeyboardFocus(link);
      await page.keyboard.press('Enter');
      await expect(link).toHaveAttribute('aria-current', 'location');
      await expect(page.locator(hash)).toBeVisible();
      if (hash === '#salud-destinos') {
        await expect(page.locator('#salud-destinos[role="alert"]'))
          .toContainText('Salud de destinos no disponible');
      }
      await noDocumentOverflow(page, width, hash);
    }

    await expect(page.locator('#colecciones').getByRole('status')
      .getByText(/No hay colecciones/)).toBeVisible();
    await expect(page.locator('#entregas-manuales').getByRole('status')
      .getByText('No hay entregas manuales pendientes.')).toBeVisible();
    await expect(page.locator('#reglas').getByText(
      'No hay una regla semanal configurada.')).toBeVisible();
    await expect(page.getByRole('status')
      .getByText('No hay actividad interna registrada en esta semana.')).toBeVisible();
    expect(reads.every(({ method }) => method === 'GET')).toBe(true);
  });

  test('MVP privado: contexto 401 avisa y conserva foco accesible a ' +
    width + 'px', async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await admin(page, { failContext: true });
    await expect(page.getByRole('alert')).toContainText('Acceso sintético expirado.');
    await expect(page.getByRole('link', { name: 'Volver a seleccionar organización' }))
      .toBeVisible();
    await noDocumentOverflow(page, width, '401');
  });
}
