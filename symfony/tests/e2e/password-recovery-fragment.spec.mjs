import { test, expect } from '@playwright/test';

test('password recovery fragment is transferred under the production CSP', async ({ page }) => {
  const token = 'A'.repeat(43);
  const response = await page.goto(`/recover-password#token=${token}`);
  expect(response).not.toBeNull();
  expect(response.headers()['content-security-policy']).toContain("script-src 'self'");

  await expect(page.locator('script[src^="/assets/password-recovery.js"]')).toHaveCount(1);
  await expect(page.locator('#password-recovery-token')).toHaveValue(token);
  await expect(page).toHaveURL(/\/recover-password$/);
  expect(new URL(page.url()).hash).toBe('');
});

test('password recovery fragment rejects malformed tokens and still clears the URL', async ({ page }) => {
  await page.goto('/recover-password#token=not-a-valid-reset-token');

  await expect(page.locator('#password-recovery-token')).toHaveValue('');
  await expect(page).toHaveURL(/\/recover-password$/);
  expect(new URL(page.url()).hash).toBe('');
});
