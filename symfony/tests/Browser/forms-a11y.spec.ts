import { test, expect } from '@playwright/test';

/**
 * Supplemental browser contract for the isolated Symfony preview.
 * This claimed path is outside playwright.config.mjs's default testDir
 * (tests/e2e), so it requires explicit discovery in a dedicated run.
 * It never connects to production or uses personal accounts.
 */
for (const width of [360, 390, 820]) {
  test(`native invalid submission focuses the labelled first field at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 740 });
    await page.goto('/login');
    const email = page.getByRole('textbox', { name: 'Correo electrónico' });
    const password = page.getByLabel('Contraseña');
    await expect(email).toHaveAttribute('required', '');
    await expect(password).toHaveAttribute('type', 'password');
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(email).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
  });

  test(`invalid login response is an alert described by both fields at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 740 });
    await page.goto('/login');
    await page.getByLabel('Correo electrónico').fill('synthetic-invalid@example.test');
    await page.getByLabel('Contraseña').fill('invalid-synthetic-password');
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page.locator('#identity-login-error')).toHaveAttribute('role', 'alert');
    await expect(page.getByLabel('Correo electrónico')).toHaveAttribute('aria-describedby', 'identity-login-error');
    await expect(page.getByLabel('Contraseña')).toHaveAttribute('aria-describedby', 'identity-login-error');
    await expect(page.getByLabel('Contraseña')).toHaveValue('');
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
  });

  test(`recovery form labels and keyboard focus survive mobile width ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 740 });
    await page.goto('/forgot-password');
    const email = page.getByLabel('Correo electrónico');
    await expect(email).toHaveAttribute('required', '');
    await page.getByRole('button', { name: 'Enviar instrucciones' }).click();
    await expect(email).toBeFocused();
    await page.goto('/recover-password');
    await expect(page.getByLabel('Nueva contraseña')).toHaveAttribute('type', 'password');
    await expect(page.getByLabel('Confirmar contraseña')).toHaveAttribute('type', 'password');
    await expect(page.locator('#password-recovery-token')).toHaveValue('');
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
  });
}
