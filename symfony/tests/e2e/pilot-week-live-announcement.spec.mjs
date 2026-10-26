import { test, expect } from '@playwright/test';

// S5 accessibility regression: synthetic tenant, preview-local GET mocks only.
// No production requests, provider calls, credentials, or mutating API calls.
function mondayUtc() {
  const date = new Date();
  const monday = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(),
    date.getUTCDate() - ((date.getUTCDay() + 6) % 7)));
  return monday.toISOString().slice(0, 10);
}

function shiftUtc(start, days) {
  const date = new Date(start + 'T00:00:00Z');
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

function summaryFor(week) {
  const days = Array.from({ length: 7 }, (_, offset) => ({
    date_utc: shiftUtc(week, offset),
    prepared_attempts: 0,
    completed_reports: 0,
    failed_attempts: 0,
  }));
  return {
    ready: true,
    week_start_utc: week,
    week_end_exclusive_utc: shiftUtc(week, 7),
    days,
    totals: { prepared_attempts: 0, completed_reports: 0, failed_attempts: 0 },
    traffic: { ready: false, clicks: null },
    external_publications_verified: null,
    provider_calls: false,
    mvp: {
      content: {
        ready: false, available_assets: null, original_blobs: null,
        used_bytes: null, eligibility_ready: false, ready_to_schedule: null,
      },
      upcoming: { ready: true, total: 0, items: [] },
      deliveries: { ready: true, counts: { published: 0 }, recent: [] },
      traffic: { ready: false, clicks: null },
    },
  };
}

for (const width of [360, 820]) {
  test('Semana del piloto se anuncia una vez por selección, conserva foco y recupera 503 en ' +
    width + 'px', async ({ page }) => {
    await page.setViewportSize({ width, height: 740 });
    await page.goto('/preview');
    const script = await page.locator('script[type="module"]').getAttribute('src');
    expect(script).toBeTruthy();

    const currentWeek = mondayUtc();
    const previousWeek = shiftUtc(currentWeek, -7);
    const failedWeek = shiftUtc(currentWeek, -14);
    const reads = [];
    const mutations = [];

    page.on('request', (request) => {
      const url = new URL(request.url());
      if (url.pathname.startsWith('/api/admin/') && !['GET', 'HEAD'].includes(request.method())) {
        mutations.push(request.method() + ' ' + url.pathname);
      }
    });

    await page.route('**/api/admin/context', (route) => route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: {
        user: { display_name: 'Persona sintética' },
        organization: {
          id: '00000000-0000-7000-8000-000000000088',
          name: 'Piloto descartable', role: 'model',
        },
        permissions: {
          workspace_view: true, organization_manage: false,
          content_prepare: false, content_review: true,
        },
        weekly_rule_csrf: null,
      } }),
    }));
    await page.route('**/api/admin/pilot/weekly-summary?week=*', (route) => {
      expect(route.request().method()).toBe('GET');
      const week = new URL(route.request().url()).searchParams.get('week');
      reads.push(week);
      return route.fulfill({
        status: week === failedWeek ? 503 : 200,
        contentType: 'application/json',
        body: JSON.stringify(week === failedWeek
          ? { error: { message: 'Semana sintética temporalmente no disponible.' } }
          : { data: summaryFor(week) }),
      });
    });

    await page.evaluate(() => {
      document.body.innerHTML = '<div class="admin-page"><main id="contenido" tabindex="-1">' +
        '<div id="grindflow-admin"></div></main></div>';
    });
    await page.addScriptTag({ url: script + '?pilot-week-live-announcement=' + width, type: 'module' });
    const navigation = page.getByRole('navigation', { name: 'Navegación administrativa' });
    await navigation.getByRole('link', { name: /Piloto/ }).click();
    await expect(page.getByRole('heading', { name: 'Resumen semanal del piloto' })).toBeVisible();

    const weeks = page.getByRole('navigation', { name: 'Semanas del piloto' });
    const previous = weeks.getByRole('button', { name: 'Semana anterior' });
    const next = weeks.getByRole('button', { name: 'Semana siguiente' });
    const announcement = weeks.getByRole('status');

    await expect(announcement).toHaveCount(1);
    await expect(announcement).toHaveAttribute('aria-live', 'polite');
    await expect(announcement).toHaveAttribute('aria-atomic', 'true');
    await expect(announcement).toHaveText('Semana del ' + currentWeek + ' (UTC)');
    await expect.poll(() => reads.includes(currentWeek)).toBe(true);
    await expect(page.getByRole('status').getByText('No hay actividad interna registrada en esta semana.'))
      .toBeVisible();

    // Only keyboard Tab should reach the previous-week control.
    let reached = false;
    for (let attempt = 0; attempt < 64; attempt += 1) {
      await page.keyboard.press('Tab');
      if (await previous.evaluate((node) => node === document.activeElement)) {
        reached = true;
        break;
      }
    }
    expect(reached, 'Week control must be keyboard accessible').toBe(true);
    await expect(previous).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(previous).toBeFocused();
    await expect(announcement).toHaveText('Semana del ' + previousWeek + ' (UTC)');
    await expect.poll(() => reads.includes(previousWeek)).toBe(true);
    await expect(page.getByRole('status').getByText('No hay actividad interna registrada en esta semana.'))
      .toBeVisible();

    // Status content must remain stable through loading and success, not announce twice.
    await expect(announcement).toHaveCount(1);
    await expect(weeks.getByRole('status')).toHaveText('Semana del ' + previousWeek + ' (UTC)');
    await page.keyboard.press('Space');
    await expect(previous).toBeFocused();
    await expect(announcement).toHaveText('Semana del ' + failedWeek + ' (UTC)');
    await expect.poll(() => reads.includes(failedWeek)).toBe(true);
    await expect(page.locator('.pilot-summary').getByRole('alert'))
      .toHaveText('Semana sintética temporalmente no disponible.');
    await expect(page.locator('.pilot-summary').getByRole('alert')).toHaveCount(1);
    await expect(weeks.getByRole('status')).toHaveCount(1);
    await expect(page.locator('.pilot-totals')).toHaveCount(0);

    // 503 remains a separate alert; recovery navigates without stealing keyboard focus.
    await page.keyboard.press('Tab');
    await expect(next).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(next).toBeFocused();
    await expect(announcement).toHaveText('Semana del ' + previousWeek + ' (UTC)');
    await expect(page.locator('.pilot-summary').getByRole('alert')).toHaveCount(0);
    await expect(page.getByRole('status').getByText('No hay actividad interna registrada en esta semana.'))
      .toBeVisible();

    await page.keyboard.press('Enter');
    await expect(announcement).toHaveText('Semana del ' + currentWeek + ' (UTC)');
    // The next button becomes disabled at the upper bound; browsers may naturally blur it.
    await expect(next).toBeDisabled();
    await expect(weeks.getByRole('status')).toHaveCount(1);
    // StrictMode may fetch the same week twice on mount; only ordered transitions matter.
    const transitions = reads.filter((week, index) => index === 0 || week !== reads[index - 1]);
    expect(transitions).toEqual([currentWeek, previousWeek, failedWeek, previousWeek, currentWeek]);
    expect(mutations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth))
      .toBeLessThanOrEqual(width);
  });
}
