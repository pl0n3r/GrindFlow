import { test, expect, type Page } from '@playwright/test';

/**
 * Responsive acceptance for public, anonymous MVP routes only.
 * No production host, credentials, private API, external network or stateful requests.
 */
const widths = [360, 390, 768] as const;
const routes = ['/', '/preview', '/login'] as const;
const origin = new URL(process.env.S0_BASE_URL ?? 'http://127.0.0.1:8765');
if (origin.protocol !== 'http:' || origin.hostname !== '127.0.0.1' ||
    !origin.port || origin.username || origin.password ||
    origin.pathname !== '/' || origin.search || origin.hash) {
  throw new Error('NO-GO: responsive suite requires an explicit loopback HTTP base URL');
}

test.use({ serviceWorkers: 'block', viewport: { width: 390, height: 780 } });

export type ResponsiveDefect = 'overflow' | 'control-inaccesible' | 'texto-cortado';

export function responsiveDefects(): ResponsiveDefect[] {
  const defects = new Set<ResponsiveDefect>();
  const width = document.documentElement.clientWidth;
  if (document.documentElement.scrollWidth > width + 1 ||
      document.body.scrollWidth > width + 1) {
    defects.add('overflow');
  }
  const elements = [...document.querySelectorAll<HTMLElement>(
    'button, input, select, textarea, a[href], [role="button"]',
  )];
  for (const el of elements) {
    const style = getComputedStyle(el);
    if (style.display === 'none' || style.visibility === 'hidden' ||
        el.getAttribute('aria-hidden') === 'true') continue;
    const rect = el.getBoundingClientRect();
    if (!rect.width || !rect.height) continue;
    if (rect.left < -1 || rect.right > width + 1) {
      // Controls in a deliberately horizontally scrollable region remain reachable.
      // An ordinary off-screen button (without that scrollport) must still fail.
      let scrollport: HTMLElement | null = el.parentElement;
      let horizontallyReachable = false;
      while (scrollport && scrollport !== document.body) {
        const css = getComputedStyle(scrollport);
        if (/(auto|scroll)/.test(css.overflowX) &&
            scrollport.scrollWidth > scrollport.clientWidth + 1) {
          horizontallyReachable = true;
          break;
        }
        scrollport = scrollport.parentElement;
      }
      if (!horizontallyReachable) defects.add('control-inaccesible');
    }
  }
  for (const el of document.querySelectorAll<HTMLElement>(
    'h1, h2, h3, label, button, [role="heading"]',
  )) {
    if (getComputedStyle(el).display === 'none') continue;
    const style = getComputedStyle(el);
    if (style.overflowX === 'hidden' || style.textOverflow === 'ellipsis' ||
        style.whiteSpace === 'nowrap') {
      if (el.scrollWidth > el.clientWidth + 1) defects.add('texto-cortado');
    }
  }
  return [...defects];
}

async function isolateTraffic(page: Page) {
  await page.routeWebSocket('**/*', (socket) => socket.close());
  await page.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== origin.origin || !['GET', 'HEAD'].includes(request.method())) {
      return route.abort();
    }
    if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/admin/')) {
      return route.fulfill({ status: 403, contentType: 'application/json',
        body: '{"error":"synthetic_denied"}' });
    }
    return route.continue();
  });
}

for (const path of routes) {
  for (const width of widths) {
    test(`public ${path} at ${width}px has no horizontal or clipped controls`, async ({ page }) => {
      await page.setViewportSize({ width, height: 780 });
      await isolateTraffic(page);
      const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
      expect(response?.status(), `No valid HTTP response for ${path}`).toBe(200);
      await expect(page.locator('body')).toBeVisible();
      // Anonymous public UI only; screenshots must never include private state.
      await page.screenshot({ path: test.info().outputPath(
        `public-${path === '/' ? 'home' : path.slice(1)}-${width}.png`), fullPage: true });
      expect(await page.evaluate(responsiveDefects)).toEqual([]);
    });
  }
}

for (const width of widths) {
  test(`negative responsive regressions at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 780 });
    await page.setContent('<!doctype html><html><body><main><h1>Prueba sintética</h1></main></body></html>');
    expect(await page.evaluate(responsiveDefects)).toEqual([]);

    await page.locator('body').evaluate((el) => {
      const wide = document.createElement('div');
      wide.id = 'synthetic-overflow'; wide.style.width = '2000px';
      wide.textContent = 'overflow'; el.append(wide);
    });
    expect(await page.evaluate(responsiveDefects)).toContain('overflow');
    await page.locator('#synthetic-overflow').evaluate((el) => el.remove());

    await page.locator('body').evaluate((el) => {
      const heading = document.createElement('h2');
      heading.id = 'synthetic-clipped';
      heading.textContent = 'Texto sintético intencionalmente largo '.repeat(16);
      Object.assign(heading.style, { width: '90px', overflow: 'hidden', whiteSpace: 'nowrap' });
      el.append(heading);
    });
    expect(await page.evaluate(responsiveDefects)).toContain('texto-cortado');
    await page.locator('#synthetic-clipped').evaluate((el) => el.remove());

    await page.locator('body').evaluate((el) => {
      const button = document.createElement('button');
      button.id = 'synthetic-unreachable'; button.textContent = 'Acción';
      Object.assign(button.style, { position: 'absolute', left: '2000px' });
      el.append(button);
    });
    expect(await page.evaluate(responsiveDefects)).toContain('control-inaccesible');
  });
}
