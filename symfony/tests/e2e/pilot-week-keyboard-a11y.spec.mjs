import { test, expect } from '@playwright/test';

// S5 pilot overview: all accounts, organizations, and API responses are synthetic.
// No external providers, production endpoints, writes, or private credentials.
for (const width of [360, 820]) {
  test('S5 pilot week selector supports keyboard focus, empty and 503 at ' + width + 'px', async ({ page }) => {
    await page.setViewportSize({ width, height: 740 });
    await page.goto('/preview');
    const script = await page.locator('script[type="module"]').getAttribute('src');
    expect(script).toBeTruthy();

    const monday = new Date();
    const today = new Date(Date.UTC(monday.getUTCFullYear(), monday.getUTCMonth(), monday.getUTCDate()));
    today.setUTCDate(today.getUTCDate() - ((today.getUTCDay() + 6) % 7));
    const week = today.toISOString().slice(0, 10);
    const previousDate = new Date(today);
    previousDate.setUTCDate(previousDate.getUTCDate() - 7);
    const previousWeek = previousDate.toISOString().slice(0, 10);
    const failedDate = new Date(previousDate);
    failedDate.setUTCDate(failedDate.getUTCDate() - 7);
    const failedWeek = failedDate.toISOString().slice(0, 10);

    const reads = [];
    const unsafeRequests = [];
    page.on('request', (request) => {
      const url = new URL(request.url());
      if (url.pathname.startsWith('/api/admin/') && !['GET', 'HEAD'].includes(request.method())) {
        unsafeRequests.push(request.method() + ' ' + url.pathname);
      }
    });

    await page.route('**/api/admin/context', (route) => route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: {
        user: { display_name: 'Persona sintética' },
        organization: {
          id: '00000000-0000-7000-8000-000000000088',
          name: 'Piloto descartable',
          role: 'model',
        },
        permissions: {
          workspace_view: true,
          organization_manage: false,
          content_prepare: false,
          content_review: true,
        },
        weekly_rule_csrf: null,
      } }),
    }));
    await page.route('**/api/admin/pilot/weekly-summary?week=*', (route) => {
      expect(route.request().method()).toBe('GET');
      const date = new URL(route.request().url()).searchParams.get('week');
      reads.push(date);
      if (date === failedWeek) {
        return route.fulfill({
          status: 503,
          contentType: 'application/json',
          body: JSON.stringify({ error: { message: 'Resumen sintético temporalmente no disponible.' } }),
        });
      }
      const empty = date === previousWeek;
      const days = Array.from({ length: 7 }, (_, offset) => {
        const day = new Date(date + 'T00:00:00Z');
        day.setUTCDate(day.getUTCDate() + offset);
        return {
          date_utc: day.toISOString().slice(0, 10),
          prepared_attempts: empty || offset ? 0 : 2,
          completed_reports: empty || offset ? 0 : 1,
          failed_attempts: empty || offset ? 0 : 1,
        };
      });
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: {
          ready: true,
          week_start_utc: date,
          week_end_exclusive_utc: '',
          days,
          totals: {
            prepared_attempts: empty ? 0 : 2,
            completed_reports: empty ? 0 : 1,
            failed_attempts: empty ? 0 : 1,
          },
          traffic: { ready: false, clicks: null },
          external_publications_verified: null,
          provider_calls: false,
        } }),
      });
    });

    await page.evaluate(() => {
      document.body.innerHTML = '<div class="admin-page"><main id="contenido" tabindex="-1"><div id="grindflow-admin"></div></main></div>';
    });
    await page.addScriptTag({ url: script + '?pilot-week-keyboard-e2e=' + width, type: 'module' });
    const navigation = page.getByRole('navigation', { name: 'Navegación administrativa' });
    await navigation.getByRole('link', { name: /Piloto/ }).click();
    await expect(page.getByRole('heading', { name: 'Resumen semanal del piloto' })).toBeVisible();
    const weeks = page.getByRole('navigation', { name: 'Semanas del piloto' });
    const previous = weeks.getByRole('button', { name: 'Semana anterior' });
    const next = weeks.getByRole('button', { name: 'Semana siguiente' });
    await expect(previous).toHaveAccessibleName('Semana anterior');
    await expect(next).toHaveAccessibleName('Semana siguiente');
    await expect(weeks).toContainText(week);
    await expect.poll(() => reads.includes(week)).toBe(true);

    // Keyboard-only travel between the two real buttons (not locator.click).
    await previous.focus();
    await page.keyboard.press('Tab');
    await expect(next).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    await expect(previous).toBeFocused();
    const visibleFocus = await previous.evaluate((node) => ({
      focusVisible: node.matches(':focus-visible'),
      outlineStyle: getComputedStyle(node).outlineStyle,
      outlineWidth: parseFloat(getComputedStyle(node).outlineWidth),
    }));
    expect(visibleFocus.focusVisible).toBe(true);
    expect(visibleFocus.outlineStyle).not.toBe('none');
    expect(visibleFocus.outlineWidth).toBeGreaterThan(0);

    await page.keyboard.press('Enter');
    await expect(previous).toBeFocused();
    await expect(weeks).toContainText(previousWeek);
    await expect.poll(() => reads.includes(previousWeek)).toBe(true);
    await expect(page.getByRole('status').getByText('No hay actividad interna registrada en esta semana.')).toBeVisible();
    await expect(page.locator('.pilot-totals strong')).toHaveText(['0', '0', '0']);

    // Space is intentionally exercised on the same focused control after re-render.
    await page.keyboard.press('Space');
    await expect(previous).toBeFocused();
    await expect(weeks).toContainText(failedWeek);
    await expect.poll(() => reads.includes(failedWeek)).toBe(true);
    await expect(page.locator('.pilot-summary').getByRole('alert'))
      .toContainText('Resumen sintético temporalmente no disponible.');
    await expect(page.locator('.pilot-totals')).toHaveCount(0);

    // Recovery is also keyboard-operated; the 503 must not trap keyboard focus.
    await page.keyboard.press('Tab');
    await expect(next).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(next).toBeFocused();
    await expect(weeks).toContainText(previousWeek);
    await expect(page.getByRole('status').getByText('No hay actividad interna registrada en esta semana.')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    expect(unsafeRequests).toEqual([]);
  });
}
