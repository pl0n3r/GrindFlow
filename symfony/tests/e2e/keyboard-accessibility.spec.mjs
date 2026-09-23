import { test, expect } from '@playwright/test';

// Keyboard-only regressions on disposable Symfony. No real accounts used.
for (const width of [360, 820]) {
  test(`skip link moves focus to content and login fields show a focus ring at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 740 });
    for (const path of ['/', '/preview', '/login']) {
      await page.goto(path);
      await page.keyboard.press('Tab');
      const skip = page.getByRole('link', { name: 'Saltar al contenido' });
      await expect(skip).toBeFocused();
      await expect(skip).toBeVisible();
      await page.keyboard.press('Enter');
      await expect(page.locator('main#contenido[tabindex="-1"]')).toBeFocused();
      expect(await page.evaluate(() => document.documentElement.scrollWidth))
        .toBeLessThanOrEqual(width);
    }

    const email = page.getByLabel('Correo electrónico');
    await email.focus();
    await expect(email).toBeFocused();
    const focus = await email.evaluate((node) => ({
      outlineStyle: getComputedStyle(node).outlineStyle,
      outlineWidth: getComputedStyle(node).outlineWidth,
    }));
    expect(focus).toEqual({ outlineStyle: 'solid', outlineWidth: '3px' });
    const password = page.getByLabel('Contraseña');
    await page.keyboard.press('Tab');
    await expect(password).toBeFocused();

    // Exercise the shared :focus-visible rule for controls not present on the
    // login form so a selector regression cannot hide keyboard focus.
    await password.evaluate((node) => {
      node.insertAdjacentHTML(
        'afterend',
        '<select aria-label="Select de prueba"><option>Opción</option></select>' +
          '<textarea aria-label="Textarea de prueba"></textarea>',
      );
    });

    for (const control of [
      page.getByLabel('Select de prueba'),
      page.getByLabel('Textarea de prueba'),
    ]) {
      await page.keyboard.press('Tab');
      await expect(control).toBeFocused();
      expect(await control.evaluate((node) => ({
        outlineStyle: getComputedStyle(node).outlineStyle,
        outlineWidth: getComputedStyle(node).outlineWidth,
      }))).toEqual({ outlineStyle: 'solid', outlineWidth: '3px' });
    }
  });
}
