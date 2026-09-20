import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30000,
  workers: 1,
  retries: 0,
  reporter: 'list',
  outputDir: 'tests/e2e/artifacts',
  use: {
    baseURL: process.env.S0_BASE_URL ?? 'http://127.0.0.1:8765',
    browserName: 'chromium',
    headless: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
});
