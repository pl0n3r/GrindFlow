import { test, expect, type Page } from '@playwright/test';

/**
 * MVP UI-state regressions against the real Symfony/React preview bundle.
 * A fictitious organization and intercepted same-origin GETs are the only
 * inputs: no real accounts, credentials, providers or production traffic.
 * The browser suite can be run explicitly with:
 *   npx playwright test --config=playwright.config.mjs tests/Browser/ui-states.spec.ts
 */
const organization = '00000000-0000-7000-8000-000000000088';
const context = {
  data: {
    user: { display_name: 'Persona sintética' },
    organization: { id: organization, name: 'Organización ficticia', role: 'model' },
    permissions: {
      workspace_view: true, organization_manage: false,
      content_prepare: false, manual_handoff_manage: false,
    },
    vault_upload_csrf: null, vault_manage_csrf: null,
  },
};

async function mountAdmin(page: Page) {
  await page.goto('/preview');
  const script = await page.locator('script[type="module"]').first().getAttribute('src');
  expect(script).toBeTruthy();
  await page.evaluate(() => {
    document.body.innerHTML = '<div class="admin-page"><main id="contenido" tabindex="-1">' +
      '<div id="grindflow-admin"></div></main></div>';
  });
  // /preview already executed this ES module for its original preview root.
  // Force a distinct module URL to mount the synthetic admin root after replacing DOM.
  await page.addScriptTag({ url: script! + '?ui-states-admin=1', type: 'module' });
}

function monitorWrites(page: Page) {
  const writes: string[] = [];
  page.on('request', (request) => {
    const path = new URL(request.url()).pathname;
    if (path.startsWith('/api/admin/') && !['GET', 'HEAD'].includes(request.method())) {
      writes.push(request.method() + ' ' + path);
    }
  });
  return writes;
}

test('contexto privado anuncia carga y muestra error accesible sin filtrar detalles', async ({ page }) => {
  const writes = monitorWrites(page);
  let releaseContext: (() => void) | undefined;
  const blocked = new Promise<void>((resolve) => { releaseContext = resolve; });

  await page.route('**/api/admin/context', async (route) => {
    await blocked;
    await route.fulfill({
      status: 503, contentType: 'application/json',
      body: JSON.stringify({ error: { code: 'unavailable',
        message: 'Servicio temporalmente no disponible.' } }),
    });
  });
  await mountAdmin(page);
  await expect(page.locator('.admin-state[role="status"]')).toContainText('Cargando contexto seguro');
  releaseContext!();
  const error = page.locator('.admin-state[role="alert"]');
  await expect(error).toContainText('No se pudo abrir el espacio');
  await expect(error).toContainText('Servicio temporalmente no disponible.');
  await expect(error.getByRole('link', { name: /Volver a seleccionar organización/ })).toBeVisible();
  expect(writes).toEqual([]);
});

for (const scenario of ['vacio', 'error'] as const) {
  test('colecciones muestran carga y ' + scenario + ' de forma distinguible', async ({ page }) => {
    const writes = monitorWrites(page);
    let releaseCollections: (() => void) | undefined;
    const blocked = new Promise<void>((resolve) => { releaseCollections = resolve; });

    // Deny all unrelated APIs. A synthetic 200 response with a partial shape
    // would break the Vault component (it expects data.assets), unmounting
    // the workspace and masking the collection state under test.
    await page.route('**/api/admin/**', (route) => route.fulfill({
      status: 403, contentType: 'application/json',
      body: JSON.stringify({ error: { code: 'synthetic_access_denied' } }),
    }));
    await page.route('**/api/admin/library/collections*', async (route) => {
      expect(route.request().method()).toBe('GET');
      await blocked;
      return route.fulfill({
        status: scenario === 'error' ? 503 : 200,
        contentType: 'application/json',
        body: JSON.stringify(scenario === 'error'
          ? { error: { code: 'temporarily_unavailable' } }
          : { data: { collections: [], has_more: false, next_cursor: null } }),
      });
    });
    await page.route('**/api/admin/context', (route) => route.fulfill({
      status: 200, contentType: 'application/json', body: JSON.stringify(context),
    }));

    await mountAdmin(page);
    const collections = page.locator('#colecciones');
    await expect(collections.getByRole('status')).toContainText('Cargando colecciones');
    releaseCollections!();
    if (scenario === 'vacio') {
      await expect(collections.getByRole('status')).toContainText(
        'No hay colecciones en esta organización.');
      await expect(collections.getByRole('alert')).toHaveCount(0);
    } else {
      await expect(collections.getByRole('alert')).toContainText(
        'No se pudieron cargar las colecciones.');
      await expect(collections.getByText('No hay colecciones en esta organización.')).toHaveCount(0);
    }
    expect(writes).toEqual([]);
  });
}

test('formulario de acceso invalido muestra alerta sin retener clave sintética', async ({ page }) => {
  await page.goto('/login');
  const password = page.getByLabel('Contraseña');
  await page.getByLabel('Correo electrónico').fill('invalid-synthetic@example.test');
  await password.fill('clave-sintetica-no-valida');
  await page.getByRole('button', { name: 'Entrar' }).click();
  await expect(page.getByRole('alert')).toContainText('No se pudo iniciar sesión');
  await expect(page.getByRole('alert')).not.toContainText('clave-sintetica-no-valida');
  await expect(password).toHaveValue('');
});
