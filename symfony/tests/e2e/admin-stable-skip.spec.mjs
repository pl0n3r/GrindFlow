import { test, expect } from '@playwright/test';

// Synthetic admin boot: the skip target is stable before, during and after hydration.
for (const width of [360, 820]) {
  test(`admin skip target survives React hydration at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 740 });
    await page.goto('/preview');
    const asset = await page.locator('script[type="module"]').getAttribute('src');
    expect(asset).toBeTruthy();

    await page.route('**/api/admin/context', (route) => route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: {
        user: { display_name: 'Persona sintética' },
        organization: { id: '00000000-0000-7000-8000-000000000080', name: 'Equipo sintético', role: 'editor' },
        permissions: { workspace_view: true, organization_manage: false, content_prepare: true, content_review: true },
      } }),
    }));

    // Match the real Twig admin structure before mounting the same built app.
    await page.evaluate(() => {
      document.body.innerHTML = `
        <a class="skip-link" href="#contenido">Saltar al contenido</a>
        <div class="admin-page"><main id="contenido" tabindex="-1">
          <div id="grindflow-admin"><section class="admin-state" aria-live="polite">Cargando espacio seguro…</section></div>
        </main></div>`;
    });
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Saltar al contenido' })).toBeFocused();
    await page.keyboard.press('Enter');
    const target = page.locator('main#contenido[tabindex="-1"]');
    await expect(target).toBeFocused();
    await page.addScriptTag({ url: asset + '?admin-stable-skip=1', type: 'module' });

    await expect(page.getByRole('heading', { name: /Tu espacio/ })).toBeVisible();
    await expect(target).toBeFocused();
    await expect(page.locator('main')).toHaveCount(1);
    await expect(page.getByRole('navigation', { name: 'Navegación administrativa' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth))
      .toBeLessThanOrEqual(width);
  });
}
