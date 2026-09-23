import { test, expect } from '@playwright/test';

// Runs against the disposable Symfony preview; no production credentials.
for (const width of [360, 820]) {
  test(`login failure is announced to both fields at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 740 });
    await page.goto('/login');

    const email = page.getByLabel('Correo electrónico');
    const password = page.getByLabel('Contraseña');
    await expect(email).not.toHaveAttribute('aria-invalid', 'true');
    await expect(password).not.toHaveAttribute('aria-invalid', 'true');

    await email.fill('synthetic@example.test');
    await password.fill('invalid-synthetic-password');
    await page.getByRole('button', { name: 'Entrar' }).click();

    const error = page.locator('#identity-login-error');
    await expect(error).toHaveAttribute('role', 'alert');
    await expect(error).toContainText('No se pudo iniciar sesión');
    for (const input of [email, password]) {
      await expect(input).toHaveAttribute('aria-invalid', 'true');
      await expect(input).toHaveAttribute('aria-describedby', 'identity-login-error');
      await expect(input).toHaveAccessibleDescription(
        'No se pudo iniciar sesión. Revisa tus datos o el estado de tu cuenta.',
      );
    }
    await expect(password).toHaveAttribute('type', 'password');
    await expect(password).toHaveValue('');
    await expect(email).toHaveValue('synthetic@example.test');
    expect(await page.evaluate(() => document.documentElement.scrollWidth))
      .toBeLessThanOrEqual(width);
  });
}
