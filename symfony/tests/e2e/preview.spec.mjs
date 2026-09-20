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
  await page.goto('/preview');
  const asset = await page.locator('script[type="module"]').getAttribute('src');
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
  await page.evaluate(() => { document.body.innerHTML = '<div class="admin-page"><div id="grindflow-admin"></div></div>'; });
  await page.addScriptTag({ url: asset + '?admin-e2e=1', type: 'module' });

  await expect(page.getByRole('heading', { name: /Tu espacio/ })).toBeVisible();
  await expect(page.getByText('Estudio seguro').first()).toBeVisible();
  await expect(page.getByText('Edición').first()).toBeVisible();
  await expect(page.getByText('Sin permiso')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Biblioteca de imágenes' })).toBeVisible();
  await expect(page.getByText(/los videos, la programación y las conexiones externas/)).toBeVisible();
});

test('admin context API is explicit JSON when no session exists', async ({ request }) => {
  const response = await request.get('/api/admin/context', { headers: { Accept: 'application/json' } });
  expect(response.status()).toBe(401);
  const payload = await response.json();
  expect(payload.error.code).toBe('authentication_required');
  expect(JSON.stringify(payload)).not.toContain('organization_id');
});


test('organization manager can rename selected tenant in mobile React without an ID from the browser', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/preview');
  const asset = await page.locator('script[type="module"]').getAttribute('src');
  expect(asset).toBeTruthy();

  await page.route('**/api/admin/context', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({
      data: {
        user: { display_name: 'Persona sintética' },
        organization: { id: '00000000-0000-7000-8000-000000000010', name: 'Organización inicial', role: 'studio' },
        permissions: { workspace_view: true, organization_manage: true, content_prepare: true, content_review: true },
        organization_name_csrf: 'synthetic-csrf-token',
      },
      meta: { version: '0.1.35' },
    }),
  }));

  await page.route('**/api/admin/organization/name', async (route) => {
    expect(route.request().method()).toBe('POST');
    expect(route.request().headers()['x-csrf-token']).toBe('synthetic-csrf-token');
    expect(route.request().postDataJSON()).toEqual({ name: 'Nuevo nombre' });
    await route.fulfill({
      status: 200, contentType: 'application/json',
      body: JSON.stringify({
        data: { organization: { id: '00000000-0000-7000-8000-000000000010', name: 'Nuevo nombre', role: 'studio' } }
      }),
    });
  });
  await page.evaluate(() => { document.body.innerHTML = '<div class="admin-page"><div id="grindflow-admin"></div></div>'; });
  await page.addScriptTag({ url: asset + '?settings-e2e=1', type: 'module' });
  await expect(page.getByRole('heading', { name: 'Nombre de la organización' })).toBeVisible();
  await page.getByLabel('Nombre visible').fill('Nuevo nombre');
  await page.getByRole('button', { name: 'Guardar cambios' }).click();
  await expect(page.getByText('Nombre de la organización actualizado.')).toBeVisible();
  await expect(page.getByText('Nuevo nombre').first()).toBeVisible();
});

test('editor can update only their own profile name from a 360px React panel', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/preview');
  const asset = await page.locator('script[type="module"]').getAttribute('src');
  expect(asset).toBeTruthy();

  await page.route('**/api/admin/context', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({
      data: {
        user: { display_name: 'Persona original' },
        organization: { id: '00000000-0000-7000-8000-000000000033', name: 'Organización sin cambios', role: 'editor' },
        permissions: { workspace_view: true, organization_manage: false, content_prepare: true, content_review: true },
        organization_name_csrf: null,
        profile_name_csrf: 'profile-csrf-test',
      },
      meta: { version: '0.1.37' },
    }),
  }));
  await page.route('**/api/admin/profile/name', async (route) => {
    expect(route.request().method()).toBe('POST');
    expect(route.request().headers()['x-csrf-token']).toBe('profile-csrf-test');
    expect(route.request().postDataJSON()).toEqual({ name: 'Persona actualizada' });
    await route.fulfill({
      status: 200, contentType: 'application/json',
      body: JSON.stringify({ data: { user: { display_name: 'Persona actualizada' } } }),
    });
  });

  await page.evaluate(() => { document.body.innerHTML = '<div class="admin-page"><div id="grindflow-admin"></div></div>'; });
  await page.addScriptTag({ url: asset + '?profile-e2e=1', type: 'module' });
  await expect(page.getByRole('heading', { name: 'Perfil personal' })).toBeVisible();
  await page.getByLabel('Nombre en tu perfil').fill('Persona actualizada');
  await page.getByRole('button', { name: 'Guardar perfil' }).click();
  await expect(page.getByText('Nombre de tu perfil actualizado.')).toBeVisible();
  await expect(page.locator('.admin-profile-current')).toContainText('Nombre actual: Persona actualizada');
  await expect(page.getByText('Organización sin cambios').first()).toBeVisible();
  const overflow = await page.evaluate(() => ({
    page: document.documentElement.scrollWidth,
    offenders: Array.from(document.querySelectorAll('body *'))
      .filter((element) => element.getBoundingClientRect().right > window.innerWidth + 1)
      .slice(0, 8).map((element) => ({
        element: element.tagName + '.' + element.className,
        right: Math.round(element.getBoundingClientRect().right),
      })),
  }));
  expect(overflow.page, JSON.stringify(overflow)).toBeLessThanOrEqual(360);
});

test('profile API never redirects anonymous writes to a private HTML page', async ({ request }) => {
  const response = await request.post('/api/admin/profile/name', { data: { name: 'No autorizado' }, maxRedirects: 0 });
  expect(response.status()).toBe(401);
  expect((await response.json()).error.code).toBe('authentication_required');
  expect(response.headers()['cache-control']).toContain('no-store');
});


test('S2 photo library allows a mobile editor to upload and see a private asset', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/preview');
  const asset = await page.locator('script[type="module"]').getAttribute('src');
  expect(asset).toBeTruthy();
  const id = '00000000-0000-7000-8000-000000000038';
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGOokDsBAAJwAV+M1KYSAAAAAElFTkSuQmCC', 'base64');

  await page.route('**/api/admin/context', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({
      data: {
        user: { display_name: 'Editor de prueba' },
        organization: { id, name: 'Mi biblioteca', role: 'editor' },
        permissions: { workspace_view: true, organization_manage: false, content_prepare: true, content_review: true },
        organization_name_csrf: null, profile_name_csrf: 'profile-test',
        vault_upload_csrf: 'vault-csrf-test',
      },
    }),
  }));
  await page.route('**/api/admin/vault', (route) => {
    if (route.request().method() === 'GET') {
      return route.fulfill({ status: 200, contentType: 'application/json',
        body: JSON.stringify({ data: { assets: [], limit: 30, page: 1, pages: 0, total: 0 } }) });
    }
    expect(route.request().method()).toBe('POST');
    expect(route.request().headers()['x-csrf-token']).toBe('vault-csrf-test');
    expect(route.request().postDataBuffer().includes(png)).toBe(true);
    return route.fulfill({
      status: 201, contentType: 'application/json',
      body: JSON.stringify({ data: { asset: {
        id, name: 'foto-ejemplo.png', mime_type: 'image/png', size_bytes: png.length,
        created_at: '2026-09-20 00:00:00', download_url: '/api/admin/vault/' + id + '/download',
      } } }),
    });
  });

  await page.evaluate(() => { document.body.innerHTML = '<div class="admin-page"><div id="grindflow-admin"></div></div>'; });
  await page.addScriptTag({ url: asset + '?vault-e2e=1', type: 'module' });
  await expect(page.getByRole('heading', { name: 'Biblioteca de imágenes' })).toBeVisible();
  await expect(page.getByText('Todavía no hay imágenes en esta organización.')).toBeVisible();
  await page.getByLabel('Añadir imágenes desde tu dispositivo').setInputFiles({
    name: 'foto-ejemplo.png', mimeType: 'image/png', buffer: png,
  });
  await page.getByRole('button', { name: /Guardar 1 imagen/ }).click();
  await expect(page.getByText('1 de 1 imágenes guardadas.')).toBeVisible();
  await expect(page.getByText('foto-ejemplo.png')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Descargar' })).toHaveAttribute('href', '/api/admin/vault/' + id + '/download');
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
});

test('S2 vault API is JSON protected without a session', async ({ request }) => {
  const list = await request.get('/api/admin/vault', { maxRedirects: 0 });
  expect(list.status()).toBe(401);
  expect((await list.json()).error.code).toBe('authentication_required');
  const upload = await request.post('/api/admin/vault', { maxRedirects: 0 });
  expect(upload.status()).toBe(401);
  expect(upload.headers()['cache-control']).toContain('no-store');
});

test('S2 mobile vault navigates real paginated API metadata', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/preview');
  const asset = await page.locator('script[type="module"]').getAttribute('src');
  expect(asset).toBeTruthy();
  const id = '00000000-0000-7000-8000-000000000039';
  await page.route('**/api/admin/context', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: {
      user: { display_name: 'Editor de prueba' },
      organization: { id, name: 'Biblioteca paginada', role: 'editor' },
      permissions: { workspace_view: true, organization_manage: false, content_prepare: true, content_review: true },
      profile_name_csrf: 'profile-test', vault_upload_csrf: 'vault-test',
    } }),
  }));
  const visited = [];
  await page.route('**/api/admin/vault?page=*', (route) => {
    const number = Number(new URL(route.request().url()).searchParams.get('page'));
    visited.push(number);
    return route.fulfill({
      status: 200, contentType: 'application/json',
      body: JSON.stringify({ data: {
        assets: [{ id, name: 'pagina-' + number + '.png', mime_type: 'image/png',
          size_bytes: 100, created_at: '2026-09-20 00:00:00',
          download_url: '/api/admin/vault/' + id + '/download' }],
        page: number, pages: 2, total: 31, limit: 30,
      } }),
    });
  });
  await page.evaluate(() => { document.body.innerHTML = '<div class="admin-page"><div id="grindflow-admin"></div></div>'; });
  await page.addScriptTag({ url: asset + '?vault-pages-e2e=1', type: 'module' });
  await expect(page.getByText('31 imágenes en esta organización')).toBeVisible();
  await expect(page.getByText('pagina-1.png')).toBeVisible();
  await page.getByRole('button', { name: 'Siguiente' }).click();
  await expect(page.getByText('pagina-2.png')).toBeVisible();
  await expect(page.getByText('Página 2 de 2')).toBeVisible();
  await page.getByRole('button', { name: 'Anterior' }).click();
  await expect(page.getByText('pagina-1.png')).toBeVisible();
  expect(visited).toEqual([1, 2, 1]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
});
