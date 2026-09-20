import { test, expect } from '@playwright/test';

test('home Twig is navigable and S0 content is honest', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: /Una carga/ })).toBeVisible();
  await expect(page.getByText('La automatización externa y el piloto todavía no están habilitados.')).toBeVisible();
  await page.getByRole('link', { name: /Ver el primer avance/ }).click();
  await expect(page).toHaveURL(/\/preview$/);
  await expect(page.getByRole('heading', { name: /Tu contenido/ })).toBeVisible();
});

test('React navigation changes visible section without claiming real data', async ({ page }) => {
  await page.goto('/preview');
  await expect(page.getByRole('status')).toContainText('Todavía no gestiona archivos ni publica en redes');
  await page.getByRole('button', { name: /Programación/ }).click();
  await expect(page.getByRole('heading', { name: 'Programación' })).toBeVisible();
  await page.getByRole('button', { name: /Tráfico/ }).click();
  await expect(page.getByRole('heading', { name: 'Tráfico' })).toBeVisible();
});

test('mobile viewport keeps home and React preview usable', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/');
  await expect(page.getByRole('link', { name: /Explorar vista previa/ })).toBeVisible();
  await page.getByRole('link', { name: /Explorar vista previa/ }).click();
  await expect(page.getByRole('button', { name: /Biblioteca/ })).toBeVisible();
  const overflow = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    viewportWidth: window.innerWidth,
    offenders: Array.from(document.querySelectorAll('body *'))
      .filter((element) => element.getBoundingClientRect().right > window.innerWidth + 1)
      .slice(0, 8).map((element) => element.tagName + '.' + element.className)
  }));
  expect(overflow.scrollWidth, JSON.stringify(overflow)).toBeLessThanOrEqual(overflow.viewportWidth);
});

test('private admin is not accidentally exposed through S0', async ({ request }) => {
  const response = await request.get('/admin');
  expect(response.status()).toBe(200);
  expect(response.url()).toMatch(/\/login$/);
  expect(await response.text()).toContain('name="_csrf_token"');
  const health = await request.get('/health');
  expect(health.status()).toBe(200);
  const state = await health.json();
  expect(state.stage).toBe('s0-preview');
  expect(state).not.toHaveProperty('release_sha');
});

test('Symfony login entrypoint has CSRF and accessible error states on mobile', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/login');
  await expect(page.getByRole('heading', { name: /Ingresa a tu espacio/ })).toBeVisible();
  await expect(page.locator('input[name="_csrf_token"]')).toHaveAttribute('value', /.+/);
  await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible();
  await page.getByLabel('Correo electrónico').fill('synthetic@example.test');
  await page.getByLabel('Contraseña').fill('una-clave-incorrecta');
  await page.getByRole('button', { name: 'Entrar' }).click();
  await expect(page.getByRole('alert')).toContainText('No se pudo iniciar sesión');
  expect(await page.evaluate(() => document.documentElement.scrollWidth))
    .toBeLessThanOrEqual(360);
});


test('React admin renders role capabilities from its tenant context contract', async ({ page, request }) => {
  const preview = await request.get('/preview');
  const source = await preview.text();
  const asset = source.match(/<script type="module" src="([^"]+)"/)?.[1];
  expect(asset).toBeTruthy();

  await page.route('**/api/admin/context', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      data: {
        user: { display_name: 'Persona de prueba' },
        organization: { id: '00000000-0000-7000-8000-000000000001', name: 'Estudio seguro', role: 'editor' },
        permissions: { workspace_view: true, organization_manage: false, content_prepare: true, content_review: true },
      },
      meta: { version: '0.1.34' },
    }),
  }));
  await page.setContent('<div class="admin-page"><div id="grindflow-admin"></div></div>');
  await page.addScriptTag({ url: new URL(asset, page.url()).toString(), type: 'module' });

  await expect(page.getByRole('heading', { name: /Tu espacio/ })).toBeVisible();
  await expect(page.getByText('Estudio seguro').first()).toBeVisible();
  await expect(page.getByText('Edición').first()).toBeVisible();
  await expect(page.getByText('Sin permiso')).toBeVisible();
  await expect(page.getByText(/datos simulados/)).toBeVisible();
});

test('admin context API is explicit JSON when no session exists', async ({ request }) => {
  const response = await request.get('/api/admin/context', { headers: { Accept: 'application/json' } });
  expect(response.status()).toBe(401);
  const payload = await response.json();
  expect(payload.error.code).toBe('authentication_required');
  expect(JSON.stringify(payload)).not.toContain('organization_id');
});
