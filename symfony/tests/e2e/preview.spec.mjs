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
  const stored = [];
  await page.route('**/api/admin/vault**', (route) => {
    if (route.request().method() === 'GET') {
      return route.fulfill({ status: 200, contentType: 'application/json',
        body: JSON.stringify({ data: { assets: stored, limit: 30, page: 1,
          pages: stored.length ? 1 : 0, total: stored.length,
          quota: { used_assets: stored.length, max_assets: 100,
            used_bytes: stored.length * png.length, max_bytes: 128 * 1024 * 1024 } } }) });
    }
    expect(route.request().method()).toBe('POST');
    expect(route.request().headers()['x-csrf-token']).toBe('vault-csrf-test');
    expect(route.request().postDataBuffer().includes(png)).toBe(true);
    const saved = { id, name: 'foto-ejemplo.png', mime_type: 'image/png', size_bytes: png.length,
      created_at: '2026-09-20 00:00:00', download_url: '/api/admin/vault/' + id + '/download' };
    stored.unshift(saved);
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
  await expect(page.getByText('0 de 100 imágenes, incluida la papelera.')).toBeVisible();
  await expect(page.getByText('Espacio utilizado: 0.00 de 128 MiB')).toBeVisible();
  await page.getByLabel('Añadir imágenes desde tu dispositivo').setInputFiles({
    name: 'foto-ejemplo.png', mimeType: 'image/png', buffer: png,
  });
  await page.getByRole('button', { name: /Guardar 1 imagen/ }).click();
  await expect(page.getByText('1 de 1 imágenes guardadas.')).toBeVisible();
  await expect(page.getByText('1 de 100 imágenes, incluida la papelera.')).toBeVisible();
  await expect(page.getByText('foto-ejemplo.png', { exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Descargar' })).toHaveAttribute('href', '/api/admin/vault/' + id + '/download');
  await page.route('**/api/admin/vault/' + id, (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: { asset: {
      id, name: 'foto-ejemplo.png', mime_type: 'image/png', size_bytes: png.length,
      created_at: '2026-09-20 00:00:00', download_url: '/api/admin/vault/' + id + '/download',
    } } }),
  }));
  await page.getByRole('button', { name: 'Detalles' }).click();
  await expect(page.locator('.vault-metadata').getByText('Guardada', { exact: true })).toBeVisible();
  await expect(page.getByText(png.length + ' bytes')).toBeVisible();

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
  await expect(page.getByRole('navigation', { name: 'Páginas de la biblioteca' }).getByText('Página 2 de 2')).toBeVisible();
  await page.getByRole('button', { name: 'Anterior' }).click();
  await expect(page.getByText('pagina-1.png')).toBeVisible();
  expect(visited).toEqual([1, 2, 1]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
});


test('S2 móvil conserva éxitos parciales cuando la cuota rechaza otra imagen', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/preview');
  const asset = await page.locator('script[type="module"]').getAttribute('src');
  expect(asset).toBeTruthy();
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGOokDsBAAJwAV+M1KYSAAAAAElFTkSuQmCC', 'base64');
  const id = '00000000-0000-7000-8000-000000000041';
  await page.route('**/api/admin/context', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: {
      user: { display_name: 'Editor de cuota' },
      organization: { id, name: 'Biblioteca limitada', role: 'editor' },
      permissions: { workspace_view: true, organization_manage: false, content_prepare: true, content_review: true },
      profile_name_csrf: 'profile-test', vault_upload_csrf: 'vault-quota-test',
    } }),
  }));

  let uploads = 0;
  const stored = [];
  await page.route('**/api/admin/vault?page=*', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: {
      assets: stored, page: 1, pages: stored.length ? 1 : 0, limit: 30, total: stored.length,
      quota: { used_assets: 99 + stored.length, max_assets: 100,
        used_bytes: (99 + stored.length) * png.length, max_bytes: 128 * 1024 * 1024 },
    } }),
  }));
  await page.route('**/api/admin/vault', (route) => {
    expect(route.request().method()).toBe('POST');
    expect(route.request().headers()['x-csrf-token']).toBe('vault-quota-test');
    uploads += 1;
    if (uploads === 2) {
      return route.fulfill({ status: 409, contentType: 'application/json',
        body: JSON.stringify({ error: { code: 'vault_quota_exceeded', message: 'La biblioteca alcanzó su cuota.' } }) });
    }
    const saved = { id, name: 'uno.png', mime_type: 'image/png', size_bytes: png.length,
      created_at: '2026-09-20 00:00:00', download_url: '/api/admin/vault/' + id + '/download' };
    stored.unshift(saved);
    return route.fulfill({ status: 201, contentType: 'application/json',
      body: JSON.stringify({ data: { asset: saved } }) });
  });

  await page.evaluate(() => { document.body.innerHTML = '<div class="admin-page"><div id="grindflow-admin"></div></div>'; });
  await page.addScriptTag({ url: asset + '?vault-quota-e2e=1', type: 'module' });
  await expect(page.getByText('99 de 100 imágenes, incluida la papelera.')).toBeVisible();
  await page.getByLabel('Añadir imágenes desde tu dispositivo').setInputFiles([
    { name: 'uno.png', mimeType: 'image/png', buffer: png },
    { name: 'dos.png', mimeType: 'image/png', buffer: png },
  ]);
  await page.getByRole('button', { name: /Guardar 2 imágenes/ }).click();
  await expect(page.getByText(/1 de 2 imágenes guardadas/)).toContainText('dos.png: La biblioteca alcanzó su cuota.');
  await expect(page.getByText('100 de 100 imágenes, incluida la papelera.')).toBeVisible();
  await expect(page.getByText('uno.png', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: /Reintentar/ })).toHaveCount(0);
  expect(uploads).toBe(2);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
});




test('S2 mobile never retries ambiguous HTTP 201 or permanent 422 with malformed JSON', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/preview');
  const script = await page.locator('script[type="module"]').getAttribute('src');
  expect(script).toBeTruthy();
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGOokDsBAAJwAV+M1KYSAAAAAElFTkSuQmCC', 'base64');
  let attempts = 0;
  await page.route('**/api/admin/context', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: {
      user: { display_name: 'Editor de prueba' },
      organization: { id: '00000000-0000-7000-8000-000000000048', name: 'Lote ambiguo', role: 'editor' },
      permissions: { workspace_view: true, organization_manage: false, content_prepare: true, content_review: true },
      profile_name_csrf: 'profile-test', vault_upload_csrf: 'retry-test',
    } }),
  }));
  await page.route('**/api/admin/vault?page=*', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: { assets: [], page: 1, pages: 0, total: 0,
      quota: { used_assets: 0, max_assets: 100, used_bytes: 0,
        max_bytes: 128 * 1024 * 1024 } } }),
  }));
  await page.route('**/api/admin/vault', (route) => {
    attempts++;
    return route.fulfill({ status: attempts === 1 ? 201 : 422,
      contentType: 'text/plain', body: 'invalid json' });
  });
  await page.evaluate(() => { document.body.innerHTML = '<div class="admin-page"><div id="grindflow-admin"></div></div>'; });
  await page.addScriptTag({ url: script + '?vault-ambiguous-e2e=1', type: 'module' });
  await expect(page.getByRole('heading', { name: 'Biblioteca de imágenes' })).toBeVisible();
  await page.getByLabel('Añadir imágenes desde tu dispositivo').setInputFiles([
    { name: 'recibida.png', mimeType: 'image/png', buffer: png },
    { name: 'rechazada.png', mimeType: 'image/png', buffer: png },
  ]);
  await page.getByRole('button', { name: /Guardar 2 imágenes/ }).click();
  await expect(page.getByText(/0 de 2 imágenes guardadas/)).toBeVisible();
  await expect(page.getByText(/2 archivos requieren revisión/)).toBeVisible();
  await expect(page.getByRole('button', { name: /Reintentar/ })).toHaveCount(0);
  expect(attempts).toBe(2);
});

test('S2 mobile does not offer retry for a duplicate or invalid format', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/preview');
  const script = await page.locator('script[type="module"]').getAttribute('src');
  expect(script).toBeTruthy();
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGOokDsBAAJwAV+M1KYSAAAAAElFTkSuQmCC', 'base64');
  let attempts = 0;
  await page.route('**/api/admin/context', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: {
      user: { display_name: 'Editor de prueba' },
      organization: { id: '00000000-0000-7000-8000-000000000048', name: 'Lote con rechazos', role: 'editor' },
      permissions: { workspace_view: true, organization_manage: false, content_prepare: true, content_review: true },
      profile_name_csrf: 'profile-test', vault_upload_csrf: 'retry-test', vault_manage_csrf: 'manage-test',
    } }),
  }));
  await page.route('**/api/admin/vault?page=*', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: { assets: [], page: 1, pages: 0, total: 0,
      quota: { used_assets: 1, max_assets: 100, used_bytes: 69,
        max_bytes: 128 * 1024 * 1024 } } }),
  }));
  await page.route('**/api/admin/vault', (route) => {
    attempts++;
    return route.fulfill({ status: 409, contentType: 'application/json',
      body: JSON.stringify({ error: { code: 'vault_duplicate_active', message: 'Ya existe esta imagen.' } }) });
  });
  await page.evaluate(() => { document.body.innerHTML = '<div class="admin-page"><div id="grindflow-admin"></div></div>'; });
  await page.addScriptTag({ url: script + '?vault-reject-e2e=1', type: 'module' });
  await expect(page.getByRole('heading', { name: 'Biblioteca de imágenes' })).toBeVisible();
  await page.getByLabel('Añadir imágenes desde tu dispositivo').setInputFiles([
    { name: 'guardada.png', mimeType: 'image/png', buffer: png },
    { name: 'texto.txt', mimeType: 'text/plain', buffer: Buffer.from('no es imagen') },
  ]);
  await page.getByRole('button', { name: /Guardar 2 imágenes/ }).click();
  await expect(page.getByText(/0 de 2 imágenes guardadas/)).toBeVisible();
  await expect(page.getByText(/2 archivos requieren revisión/)).toBeVisible();
  await expect(page.getByRole('button', { name: /Reintentar/ })).toHaveCount(0);
  await expect(page.getByRole('list', { name: 'Resultado por archivo' }).getByRole('listitem')).toHaveCount(2);
  expect(attempts).toBe(1);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
});

test('S2 mobile retries only failed files after a partial multi-upload', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/preview');
  const assetScript = await page.locator('script[type="module"]').getAttribute('src');
  expect(assetScript).toBeTruthy();
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGOokDsBAAJwAV+M1KYSAAAAAElFTkSuQmCC', 'base64');
  const stored = [];
  const attempts = [];
  await page.route('**/api/admin/context', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: {
      user: { display_name: 'Editor de prueba' },
      organization: { id: '00000000-0000-7000-8000-000000000048', name: 'Lote móvil', role: 'editor' },
      permissions: { workspace_view: true, organization_manage: false, content_prepare: true, content_review: true },
      profile_name_csrf: 'profile-test', vault_upload_csrf: 'retry-test',
    } }),
  }));
  await page.route('**/api/admin/vault?page=*', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: {
      assets: stored, page: 1, pages: stored.length ? 1 : 0, total: stored.length,
      quota: { used_assets: stored.length, max_assets: 100,
        used_bytes: stored.length * png.length, max_bytes: 128 * 1024 * 1024 },
    } }),
  }));
  await page.route('**/api/admin/vault', (route) => {
    expect(route.request().headers()['x-csrf-token']).toBe('retry-test');
    const payload = route.request().postDataBuffer().toString('latin1');
    const name = ['uno.png', 'dos.png'].find((file) => payload.includes(file));
    expect(name).toBeTruthy();
    attempts.push(name);
    if (name === 'dos.png' && attempts.length === 2) {
      return route.fulfill({ status: 503, contentType: 'application/json',
        body: JSON.stringify({ error: { message: 'Error temporal. Intenta de nuevo.' } }) });
    }
    const item = { id: '00000000-0000-7000-8000-0000000000' + (48 + stored.length),
      name, mime_type: 'image/png', size_bytes: png.length,
      created_at: '2026-09-20 00:00:00', download_url: '/api/admin/vault/ejemplo/download' };
    stored.unshift(item);
    return route.fulfill({ status: 201, contentType: 'application/json',
      body: JSON.stringify({ data: { asset: item } }) });
  });
  await page.evaluate(() => { document.body.innerHTML = '<div class="admin-page"><div id="grindflow-admin"></div></div>'; });
  await page.addScriptTag({ url: assetScript + '?vault-retry-e2e=1', type: 'module' });
  await expect(page.getByRole('heading', { name: 'Biblioteca de imágenes' })).toBeVisible();
  await page.getByLabel('Añadir imágenes desde tu dispositivo').setInputFiles([
    { name: 'uno.png', mimeType: 'image/png', buffer: png },
    { name: 'dos.png', mimeType: 'image/png', buffer: png },
  ]);
  await page.getByRole('button', { name: /Guardar 2 imágenes/ }).click();
  await expect(page.getByText(/1 de 2 imágenes guardadas/)).toContainText('dos.png: Error temporal.');
  await expect(page.getByText('Procesadas 2 de 2 imágenes.')).toBeVisible();
  await expect(page.getByRole('list', { name: 'Resultado por archivo' }).getByRole('listitem')).toHaveCount(2);
  await expect(page.getByText('1 archivo pendiente.')).toBeVisible();
  await page.getByRole('button', { name: 'Reintentar 1 imagen' }).click();
  await expect(page.getByText('1 de 1 imágenes guardadas.')).toBeVisible();
  await expect(page.getByText('2 de 100 imágenes, incluida la papelera.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Reintentar 1 imagen' })).toHaveCount(0);
  expect(attempts).toEqual(['uno.png', 'dos.png', 'dos.png']);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
});

test('S2 mobile trash requires confirmation, keeps quota and restores without exposing downloads', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/preview');
  const asset = await page.locator('script[type="module"]').getAttribute('src');
  expect(asset).toBeTruthy();
  const id = '00000000-0000-7000-8000-000000000042';
  let trashed = false;
  let changes = 0;
  const item = {
    id, name: 'foto-recuperable.png', mime_type: 'image/png',
    size_bytes: 69, created_at: '2026-09-20 00:00:00',
    download_url: '/api/admin/vault/' + id + '/download',
  };
  await page.route('**/api/admin/context', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: {
      user: { display_name: 'Editor de prueba' },
      organization: { id, name: 'Organización papelera', role: 'editor' },
      permissions: { workspace_view: true, organization_manage: false, content_prepare: true, content_review: true },
      profile_name_csrf: 'profile-test', vault_upload_csrf: 'vault-test',
      vault_manage_csrf: 'trash-test',
    } }),
  }));
  await page.route('**/api/admin/vault?page=*', (route) => {
    const view = new URL(route.request().url()).searchParams.get('view') ?? 'active';
    return route.fulfill({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ data: {
        assets: (view === 'trash' ? trashed : !trashed)
          ? [{ ...item, deleted_at: trashed ? '2026-09-20 01:00:00' : null }] : [],
        view, page: 1, pages: 1, limit: 30, total: 1,
        quota: { used_assets: 1, max_assets: 100, used_bytes: 69, max_bytes: 128 * 1024 * 1024 },
      } }) });
  });
  await page.route('**/api/admin/vault/' + id + '/trash', (route) => {
    expect(route.request().method()).toBe('POST');
    expect(route.request().headers()['x-csrf-token']).toBe('trash-test');
    expect(route.request().postData()).toBeNull();
    changes += 1;
    trashed = true;
    return route.fulfill({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ data: { id, state: 'trash' } }) });
  });
  await page.route('**/api/admin/vault/' + id + '/restore', (route) => {
    expect(route.request().method()).toBe('POST');
    expect(route.request().headers()['x-csrf-token']).toBe('trash-test');
    changes += 1;
    trashed = false;
    return route.fulfill({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ data: { id, state: 'active' } }) });
  });
  await page.evaluate(() => { document.body.innerHTML = '<div class="admin-page"><div id="grindflow-admin"></div></div>'; });
  await page.addScriptTag({ url: asset + '?vault-trash-e2e=1', type: 'module' });
  await expect(page.getByText('foto-recuperable.png')).toBeVisible();
  await page.getByRole('button', { name: 'Mover a papelera' }).click();
  await expect(page.getByText(/¿Mover «foto-recuperable.png» a la papelera/)).toBeVisible();
  await page.getByRole('button', { name: 'Cancelar' }).click();
  expect(changes).toBe(0);
  await page.getByRole('button', { name: 'Mover a papelera' }).click();
  await page.getByRole('button', { name: 'Confirmar movimiento' }).click();
  await expect(page.getByText('Imagen movida a la papelera. Puedes restaurarla.')).toBeVisible();
  await expect(page.getByText('Todavía no hay imágenes en esta organización.')).toBeVisible();
  await expect(page.getByText('1 de 100 imágenes, incluida la papelera.')).toBeVisible();
  await page.getByRole('button', { name: 'Papelera' }).click();
  await expect(page.getByText('foto-recuperable.png')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Descargar' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Detalles' })).toHaveCount(0);
  await page.getByRole('button', { name: 'Restaurar' }).click();
  await expect(page.getByText('Imagen restaurada en la biblioteca.')).toBeVisible();
  await expect(page.getByText('La papelera está vacía.')).toBeVisible();
  await page.getByRole('button', { name: 'Biblioteca' }).click();
  await expect(page.getByText('foto-recuperable.png')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Descargar' })).toBeVisible();
  expect(changes).toBe(2);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
});


test('S2 mobile upload distinguishes an active duplicate from a recoverable trash duplicate', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/preview');
  const assetScript = await page.locator('script[type="module"]').getAttribute('src');
  expect(assetScript).toBeTruthy();
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGOokDsBAAJwAV+M1KYSAAAAAElFTkSuQmCC', 'base64');
  const id = '00000000-0000-7000-8000-000000000044';
  let trashed = false;
  let uploads = 0;
  const own = { id, name: 'guardada.png', mime_type: 'image/png', size_bytes: png.length,
    created_at: '2026-09-20 00:00:00',
    download_url: '/api/admin/vault/' + id + '/download' };
  await page.route('**/api/admin/context', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: {
      user: { display_name: 'Editor de prueba' },
      organization: { id, name: 'Biblioteca sin duplicados', role: 'editor' },
      permissions: { workspace_view: true, organization_manage: false, content_prepare: true, content_review: true },
      profile_name_csrf: 'profile-test', vault_upload_csrf: 'upload-test', vault_manage_csrf: 'manage-test',
    } }),
  }));
  await page.route('**/api/admin/vault?page=*', (route) => {
    const view = new URL(route.request().url()).searchParams.get('view');
    const visible = view === 'trash' ? trashed : !trashed;
    return route.fulfill({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ data: {
        assets: visible ? [{ ...own, deleted_at: trashed ? '2026-09-20 01:00:00' : null }] : [],
        page: 1, pages: visible ? 1 : 0, total: visible ? 1 : 0,
        quota: { used_assets: 1, max_assets: 100, used_bytes: png.length, max_bytes: 128 * 1024 * 1024 },
      } }) });
  });
  await page.route('**/api/admin/vault', (route) => {
    expect(route.request().method()).toBe('POST');
    expect(route.request().headers()['x-csrf-token']).toBe('upload-test');
    uploads += 1;
    return route.fulfill({ status: 409, contentType: 'application/json',
      body: JSON.stringify({ error: {
        code: trashed ? 'vault_duplicate_trash' : 'vault_duplicate_active',
        message: trashed ? 'Esta imagen ya está en tu papelera; puedes restaurarla.'
          : 'Esta imagen ya está en tu biblioteca; no se guardó otra copia.',
      } }) });
  });
  await page.route('**/api/admin/vault/' + id + '/restore', (route) => {
    expect(route.request().method()).toBe('POST');
    expect(route.request().headers()['x-csrf-token']).toBe('manage-test');
    trashed = false;
    return route.fulfill({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ data: { id, state: 'active' } }) });
  });
  await page.evaluate(() => { document.body.innerHTML = '<div class="admin-page"><div id="grindflow-admin"></div></div>'; });
  await page.addScriptTag({ url: assetScript + '?vault-dedup-e2e=1', type: 'module' });
  await expect(page.getByText('guardada.png')).toBeVisible();
  await page.getByLabel('Añadir imágenes desde tu dispositivo').setInputFiles({
    name: 'otra-copia.png', mimeType: 'image/png', buffer: png,
  });
  await page.getByRole('button', { name: /Guardar 1 imagen/ }).click();
  await expect(page.getByText(/0 de 1 imágenes guardadas/)).toContainText('ya está en tu biblioteca');
  await expect(page.getByRole('button', { name: 'Ver papelera para restaurar' })).toHaveCount(0);
  trashed = true;
  await page.getByLabel('Añadir imágenes desde tu dispositivo').setInputFiles({
    name: 'imagen-retirada.png', mimeType: 'image/png', buffer: png,
  });
  await page.getByRole('button', { name: /Guardar 1 imagen/ }).click();
  await expect(page.getByText(/0 de 1 imágenes guardadas/)).toContainText('ya está en tu papelera');
  await page.getByRole('button', { name: 'Ver papelera para restaurar' }).click();
  await expect(page.getByText('guardada.png')).toBeVisible();
  await page.getByRole('button', { name: 'Restaurar' }).click();
  await expect(page.getByText('Imagen restaurada en la biblioteca.')).toBeVisible();
  await page.getByRole('button', { name: 'Biblioteca', exact: true }).click();
  await expect(page.getByText('guardada.png')).toBeVisible();
  expect(uploads).toBe(2);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
});


test('S1 revocation hides tenant workspace, while a recoverable CSRF error keeps it visible', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/preview');
  const asset = await page.locator('script[type="module"]').getAttribute('src');
  expect(asset).toBeTruthy();
  const id = '00000000-0000-7000-8000-000000000045';
  await page.route('**/api/admin/context', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: {
      user: { display_name: 'Usuario S1' },
      organization: { id, name: 'Espacio revocable', role: 'admin' },
      permissions: { workspace_view: true, organization_manage: true, content_prepare: false, content_review: false },
      organization_name_csrf: 'csrf-s1', profile_name_csrf: 'profile-s1',
      vault_upload_csrf: null, vault_manage_csrf: null,
    } }),
  }));
  let writes = 0;
  await page.route('**/api/admin/organization/name', (route) => {
    writes += 1;
    return route.fulfill({ status: 403, contentType: 'application/json',
      body: JSON.stringify({ error: writes === 1
        ? { code: 'invalid_csrf', message: 'La solicitud ha caducado o es inválida.' }
        : { code: 'organization_access_changed', message: 'Tu acceso a esta organización ha cambiado.' } }),
    });
  });
  await page.evaluate(() => { document.body.innerHTML = '<div class="admin-page"><div id="grindflow-admin"></div></div>'; });
  await page.addScriptTag({ url: asset + '?session-s1=1', type: 'module' });
  await expect(page.getByText('Espacio revocable').first()).toBeVisible();
  await page.getByRole('button', { name: 'Guardar cambios' }).click();
  await expect(page.getByText('La solicitud ha caducado o es inválida.')).toBeVisible();
  await expect(page.getByText('Espacio revocable').first()).toBeVisible();
  await page.getByRole('button', { name: 'Guardar cambios' }).click();
  await expect(page.getByRole('link', { name: 'Volver a seleccionar organización' })).toBeVisible();
  await expect(page.getByText('Espacio revocable')).toHaveCount(0);
  expect(writes).toBe(2);
});


test('S2 mobile renames a private image without changing its download identity', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/preview');
  const asset = await page.locator('script[type="module"]').getAttribute('src');
  expect(asset).toBeTruthy();
  const id = '00000000-0000-7000-8000-000000000046';
  let name = 'original.png';
  let failFirst = true;
  await page.route('**/api/admin/context', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: {
      user: { display_name: 'Editor de prueba' },
      organization: { id, name: 'Biblioteca editable', role: 'editor' },
      permissions: { workspace_view: true, organization_manage: false, content_prepare: true, content_review: true },
      profile_name_csrf: 'profile-test', vault_upload_csrf: 'upload-test', vault_manage_csrf: 'manage-test',
    } }),
  }));
  await page.route('**/api/admin/vault?page=*', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: {
      assets: [{ id, name, mime_type: 'image/png', size_bytes: 69,
        created_at: '2026-09-20 00:00:00',
        download_url: '/api/admin/vault/' + id + '/download' }],
      page: 1, pages: 1, limit: 30, total: 1,
    } }),
  }));
  await page.route('**/api/admin/vault/' + id + '/name', (route) => {
    expect(route.request().method()).toBe('POST');
    expect(route.request().headers()['x-csrf-token']).toBe('manage-test');
    expect(route.request().postDataJSON()).toEqual({ name: 'nueva.png' });
    if (failFirst) {
      failFirst = false;
      return route.fulfill({ status: 403, contentType: 'application/json',
        body: JSON.stringify({ error: { code: 'invalid_csrf', message: 'La solicitud ha caducado.' } }) });
    }
    name = 'nueva.png';
    return route.fulfill({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ data: { id, name } }) });
  });
  await page.evaluate(() => { document.body.innerHTML = '<div class="admin-page"><div id="grindflow-admin"></div></div>'; });
  await page.addScriptTag({ url: asset + '?vault-rename-e2e=1', type: 'module' });
  await expect(page.getByText('original.png')).toBeVisible();
  await page.getByRole('button', { name: 'Renombrar' }).click();
  await page.getByLabel('Nombre de la imagen').fill('nueva.png');
  await page.getByRole('button', { name: 'Guardar nombre' }).click();
  await expect(page.getByText('La solicitud ha caducado.')).toBeVisible();
  await expect(page.getByText('original.png')).toBeVisible();
  await page.getByRole('button', { name: 'Guardar nombre' }).click();
  await expect(page.getByText('Nombre de imagen actualizado.')).toBeVisible();
  await expect(page.getByText('nueva.png')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Descargar' }))
    .toHaveAttribute('href', '/api/admin/vault/' + id + '/download');
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
});


test('S2 mobile searches private filenames in library and trash without changing quota', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/preview');
  const script = await page.locator('script[type="module"]').getAttribute('src');
  expect(script).toBeTruthy();
  const id = '00000000-0000-7000-8000-000000000047';
  const names = ['festival.png', 'ensayo.png'];
  const seen = [];
  await page.route('**/api/admin/context', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: {
      user: { display_name: 'Editor prueba' },
      organization: { id, name: 'Mi biblioteca', role: 'editor' },
      permissions: { workspace_view: true, organization_manage: false, content_prepare: true, content_review: true },
      profile_name_csrf: 'profile-test', vault_upload_csrf: 'upload-test', vault_manage_csrf: 'manage-test',
    } }),
  }));
  await page.route('**/api/admin/vault?page=*', (route) => {
    const url = new URL(route.request().url());
    const q = url.searchParams.get('q') ?? '';
    const view = url.searchParams.get('view') ?? 'active';
    seen.push([view, q, url.searchParams.get('page')]);
    const matching = view === 'trash' ? [] : names.filter((value) => value.includes(q));
    return route.fulfill({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ data: {
        assets: matching.map((name, index) => ({
          id: index === 0 ? id : '00000000-0000-7000-8000-000000000048',
          name, mime_type: 'image/png', size_bytes: 69, created_at: '2026-09-20 00:00:00',
          download_url: '/api/admin/vault/' + id + '/download',
        })),
        page: 1, pages: matching.length ? 1 : 0, limit: 30, total: matching.length,
        quota: { used_assets: 2, max_assets: 100, used_bytes: 138, max_bytes: 128 * 1024 * 1024 },
      } }),
    });
  });
  await page.evaluate(() => { document.body.innerHTML = '<div class="admin-page"><div id="grindflow-admin"></div></div>'; });
  await page.addScriptTag({ url: script + '?vault-search-e2e=1', type: 'module' });
  await expect(page.getByText('festival.png')).toBeVisible();
  await expect(page.getByText('ensayo.png')).toBeVisible();
  await page.getByRole('searchbox', { name: 'Buscar imágenes por nombre' }).fill('festival');
  await page.getByRole('button', { name: 'Buscar', exact: true }).click();
  await expect(page.getByText('festival.png')).toBeVisible();
  await expect(page.getByText('ensayo.png')).toHaveCount(0);
  await expect(page.getByText('Resultados para «festival» en biblioteca.')).toBeVisible();
  await expect(page.getByText('2 de 100 imágenes, incluida la papelera.')).toBeVisible();
  await page.getByRole('button', { name: 'Papelera', exact: true }).click();
  await expect(page.getByText('No hay imágenes que coincidan con los filtros.')).toBeVisible();
  await expect(page.getByText('Resultados para «festival» en papelera.')).toBeVisible();
  await page.getByRole('button', { name: 'Biblioteca', exact: true }).click();
  await page.getByRole('button', { name: 'Limpiar búsqueda' }).click();
  await expect(page.getByText('ensayo.png')).toBeVisible();
  expect(seen).toEqual([['active', '', '1'], ['active', 'festival', '1'],
    ['trash', 'festival', '1'], ['active', 'festival', '1'], ['active', '', '1']]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
});


test('S2 mobile combines MIME filters, backend ordering and search while keeping tenant quota', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/preview');
  const script = await page.locator('script[type="module"]').getAttribute('src');
  expect(script).toBeTruthy();
  const org = '00000000-0000-7000-8000-000000000048';
  const samples = [
    { id: '00000000-0000-7000-8000-000000000049', name: 'lago.png', mime_type: 'image/png', size_bytes: 69 },
    { id: '00000000-0000-7000-8000-000000000050', name: 'mar.webp', mime_type: 'image/webp', size_bytes: 80 },
    { id: '00000000-0000-7000-8000-000000000051', name: 'aire.jpg', mime_type: 'image/jpeg', size_bytes: 60 },
  ];
  const requests = [];
  await page.route('**/api/admin/context', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ data: {
      user: { display_name: 'Editor de muestra' },
      organization: { id: org, name: 'Colección de pruebas', role: 'editor' },
      permissions: { workspace_view: true, organization_manage: false, content_prepare: true, content_review: true },
      profile_name_csrf: 'profile-test', vault_upload_csrf: 'upload-test', vault_manage_csrf: 'manage-test',
    } }),
  }));
  await page.route('**/api/admin/vault?page=*', (route) => {
    const url = new URL(route.request().url());
    const view = url.searchParams.get('view') ?? 'active';
    const q = url.searchParams.get('q') ?? '';
    const format = url.searchParams.get('format') ?? 'all';
    const sort = url.searchParams.get('sort') ?? 'recent';
    requests.push({ view, q, format, sort, page: url.searchParams.get('page') });
    let matching = view === 'trash' ? [] : samples.filter((asset) =>
      asset.name.includes(q) && (format === 'all' || asset.mime_type ===
        ({ jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp' })[format]));
    if (sort === 'name_asc') matching = matching.toSorted((a, b) => a.name.localeCompare(b.name));
    if (sort === 'name_desc') matching = matching.toSorted((a, b) => b.name.localeCompare(a.name));
    if (sort === 'size_asc') matching = matching.toSorted((a, b) => a.size_bytes - b.size_bytes);
    if (sort === 'size_desc') matching = matching.toSorted((a, b) => b.size_bytes - a.size_bytes);
    return route.fulfill({ status: 200, contentType: 'application/json',
      body: JSON.stringify({ data: {
        assets: matching.map((asset) => ({
          ...asset, created_at: '2026-09-20 00:00:00',
          download_url: '/api/admin/vault/' + asset.id + '/download',
        })),
        page: 1, pages: matching.length ? 1 : 0, limit: 30, total: matching.length,
        format, sort, view,
        quota: { used_assets: 3, max_assets: 100, used_bytes: 209, max_bytes: 128 * 1024 * 1024 },
      } }),
    });
  });
  await page.evaluate(() => { document.body.innerHTML = '<div class="admin-page"><div id="grindflow-admin"></div></div>'; });
  await page.addScriptTag({ url: script + '?vault-format-sort-e2e=1', type: 'module' });
  await expect(page.getByText('lago.png')).toBeVisible();
  await expect(page.getByText('mar.webp')).toBeVisible();
  await page.getByLabel('Formato').selectOption('webp');
  await expect(page.getByText('mar.webp')).toBeVisible();
  await expect(page.getByText('lago.png')).toHaveCount(0);
  await expect(page.getByText('3 de 100 imágenes, incluida la papelera.')).toBeVisible();
  await page.getByLabel('Ordenar por').selectOption('size_desc');
  await expect(page.getByText('mar.webp')).toBeVisible();
  await page.getByRole('searchbox', { name: 'Buscar imágenes por nombre' }).fill('lago');
  await page.getByRole('button', { name: 'Buscar', exact: true }).click();
  await expect(page.getByText('No hay imágenes que coincidan con los filtros.')).toBeVisible();
  await page.getByLabel('Formato').selectOption('all');
  await expect(page.getByText('lago.png')).toBeVisible();
  await page.getByRole('button', { name: 'Papelera', exact: true }).click();
  await expect(page.getByText('No hay imágenes que coincidan con los filtros.')).toBeVisible();
  await expect(page.getByText('3 de 100 imágenes, incluida la papelera.')).toBeVisible();
  expect(requests.some((entry) =>
    entry.view === 'active' && entry.q === 'lago' && entry.format === 'webp' &&
    entry.sort === 'size_desc' && entry.page === '1')).toBe(true);
  expect(requests.some((entry) =>
    entry.view === 'trash' && entry.q === 'lago' && entry.format === 'all' &&
    entry.sort === 'size_desc' && entry.page === '1')).toBe(true);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
});
