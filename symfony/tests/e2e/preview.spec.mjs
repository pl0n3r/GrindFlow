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
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
  expect(overflow).toBe(false);
});

test('private admin is not accidentally exposed through S0', async ({ request }) => {
  const response = await request.get('/admin');
  expect(response.status()).toBe(403);
  const health = await request.get('/health');
  expect(health.status()).toBe(200);
  const state = await health.json();
  expect(state.stage).toBe('s0-preview');
  expect(state).not.toHaveProperty('release_sha');
});
