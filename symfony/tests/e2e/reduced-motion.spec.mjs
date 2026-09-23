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
      const layout = await page.evaluate(() => ({
        path: location.pathname,
        scrollWidth: document.documentElement.scrollWidth,
        offenders: Array.from(document.querySelectorAll('body *'))
          .filter((node) => node.getBoundingClientRect().right > innerWidth + 1)
          .slice(0, 8)
          .map((node) => node.tagName.toLowerCase() + '.' + String(node.className).slice(0, 45)),
      }));
      expect(layout.scrollWidth, JSON.stringify(layout)).toBeLessThanOrEqual(width);
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
