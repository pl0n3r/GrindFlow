import { test, expect } from '@playwright/test';

// Public Twig and React preview must honor system motion preferences.
for (const width of [360, 820]) {
  test(`reduced motion disables smooth page scrolling at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 740 });
    await page.emulateMedia({ reducedMotion: 'reduce' });

    for (const path of ['/', '/preview']) {
      await page.goto(path);
      if (path === '/preview') {
        await expect(page.getByRole('heading', { name: /Tu contenido/ })).toBeVisible();
      }
      expect(await page.evaluate(() => getComputedStyle(document.documentElement).scrollBehavior))
        .toBe('auto');
      expect(await page.evaluate(() => document.documentElement.scrollWidth))
        .toBeLessThanOrEqual(width);
    }

    const navigation = page.getByRole('navigation', { name: 'Explorador de secciones' });
    await expect(navigation).toBeVisible();
    expect(await navigation.evaluate((node) => getComputedStyle(node).scrollBehavior))
      .toBe('auto');

    // The opt-out is conditional: users without that preference retain
    // the existing smooth-scroll behavior.
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    expect(await page.evaluate(() => getComputedStyle(document.documentElement).scrollBehavior))
      .toBe('smooth');
    await page.goto('/');
    expect(await page.evaluate(() => getComputedStyle(document.documentElement).scrollBehavior))
      .toBe('smooth');
  });
}
