import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { existsSync, unlinkSync } from 'node:fs';
import { join } from 'node:path';

// GF1046: real Symfony HTTP + Chromium, against the CI's disposable MariaDB.
// No mocked API, real accounts, production writes or external provider calls.
// "Composer" currently exposes content review; "Dashboard" is read-only pilot
// reporting plus tenant settings. Do not pretend they have independent CRUD.
const BASE = process.env.S0_BASE_URL ?? 'http://127.0.0.1:8765';
const PASSWORD = 'gf1046-ci-disposable-password-only';
const UUID = () => randomUUID();
const fixed = {
  orgA: UUID(), orgB: UUID(),
  owner: UUID(), reader: UUID(), foreign: UUID(),
  assetA: UUID(), assetB: UUID(),
};
const email = (id) => id + '@example.test';
const labelA = 'GF1046 Tenant A';
const labelB = 'GF1046 Tenant B';
let uploadedAsset = null;

function disposableDatabaseOnly() {
  const url = process.env.DATABASE_URL;
  if (process.env.APP_ENV !== 'test' || !url) {
    throw new Error('GF1046 requires APP_ENV=test and an isolated DATABASE_URL');
  }
  const db = new URL(url);
  if (db.protocol !== 'mysql:' || !['127.0.0.1', 'localhost'].includes(db.hostname)
    || !/(test|ci|disposable)/i.test(db.pathname)) {
    throw new Error('GF1046 refuses non-disposable or remote databases');
  }
}

function phpFixture(mode) {
  disposableDatabaseOnly();
  const script = String.raw`require getcwd().'/vendor/autoload.php';
$f = json_decode((string) getenv('GF1046_FIXTURE'), true, 512, JSON_THROW_ON_ERROR);
$db = Doctrine\DBAL\DriverManager::getConnection(['url' => (string) getenv('DATABASE_URL')]);
$now = '2026-11-22 12:00:00';
if (getenv('GF1046_MODE') === 'seed') {
    $hash = password_hash($f['password'], PASSWORD_BCRYPT);
    foreach (['owner', 'reader', 'foreign'] as $who) {
        $db->insert('gf_identity_users', [
            'id' => $f[$who], 'name' => 'GF1046 Synthetic '.$who,
            'email' => $f[$who].'@example.test', 'password_hash' => $hash,
            'platform_role' => 'model', 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    foreach (['orgA' => 'GF1046 Tenant A', 'orgB' => 'GF1046 Tenant B'] as $key => $name) {
        $db->insert('gf_identity_organizations', [
            'id' => $f[$key], 'name' => $name,
            'slug' => 'gf1046-'.substr(str_replace('-', '', $f[$key]), 0, 24),
            'type' => 'independent', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    foreach ([['owner', 'orgA', 'admin'], ['reader', 'orgA', 'model'],
              ['foreign', 'orgB', 'admin']] as [$who, $org, $role]) {
        $db->insert('gf_identity_memberships', [
            'id' => Symfony\Component\Uid\Uuid::v7()->toRfc4122(),
            'user_id' => $f[$who], 'organization_id' => $f[$org],
            'role' => $role, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    foreach ([['assetA', 'orgA', 'owner'], ['assetB', 'orgB', 'foreign']] as [$asset, $org, $actor]) {
        $db->insert('gf_vault_assets', [
            'id' => $f[$asset], 'organization_id' => $f[$org],
            'uploaded_by' => $f[$actor], 'original_name' => 'GF1046-'.$asset.'.png',
            'mime_type' => 'image/png', 'size_bytes' => 10,
            'sha256' => hash('sha256', $f[$asset]), 'storage_key' => $f[$asset],
            'usage_scope' => 'needs_review', 'created_at' => $now,
        ]);
    }
    foreach ([['orgA', 'owner'], ['orgB', 'foreign']] as [$org, $who]) {
        $db->insert('gf_content_rules', [
            'organization_id' => $f[$org], 'timezone' => 'UTC',
            'weekdays' => 'mon,tue,wed,thu,fri,sat,sun',
            'local_time' => '23:59', 'max_per_day' => 1,
            'mode' => 'review_only', 'updated_by' => $f[$who],
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    exit(0);
}
$orgs = [$f['orgA'], $f['orgB']];
foreach ([
    'gf_manual_handoff_events', 'gf_external_publication_attempts',
    'gf_distribution_fanout_deliveries', 'gf_distribution_fanout_groups',
    'gf_schedule_drafts', 'gf_manual_destinations',
    'gf_distribution_authorization_events', 'gf_content_review_events',
    'gf_vault_asset_history', 'gf_asset_collection_items',
    'gf_asset_collections', 'gf_content_rules', 'gf_vault_assets',
    'gf_identity_memberships',
] as $table) {
    if (!$db->createSchemaManager()->tablesExist([$table])) continue;
    try {
        $db->executeStatement('DELETE FROM '.$table.' WHERE organization_id IN (?, ?)', $orgs);
    } catch (Throwable $unused) {
        // Some optional tables have a different schema; never delete unscoped rows.
    }
}
foreach (['owner', 'reader', 'foreign'] as $who) {
    $db->delete('gf_identity_users', ['id' => $f[$who]]);
}
foreach (['orgA', 'orgB'] as $org) {
    $db->delete('gf_identity_organizations', ['id' => $f[$org]]);
}
`;
  execFileSync('php', ['-r', script], {
    cwd: process.cwd(), timeout: 20000,
    env: { ...process.env, GF1046_MODE: mode, GF1046_FIXTURE: JSON.stringify({ ...fixed, password: PASSWORD }) },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

async function login(browser, user, organization) {
  const context = await browser.newContext({ baseURL: BASE });
  const page = await context.newPage();
  await page.goto('/login');
  await page.getByLabel('Correo electrónico').fill(email(fixed[user]));
  await page.getByLabel('Contraseña').fill(PASSWORD);
  await page.getByRole('button', { name: 'Entrar', exact: true }).click();
  await expect(page).toHaveURL(/\/organizations$/);
  await page.locator('.identity-orgs form').filter({ hasText: organization })
    .locator('button[type="submit"]').click();
  await expect(page).toHaveURL(/\/admin$/);
  const ctx = await context.request.get('/api/admin/context');
  expect(ctx.status()).toBe(200);
  const data = (await ctx.json()).data;
  expect(data.organization.name).toBe(organization);
  return { context, page, data };
}

async function reply(response, expected, foreignId) {
  const body = await response.text();
  expect(response.status(), body).toBe(expected);
  if (foreignId) expect(body).not.toContain(foreignId);
  return JSON.parse(body).data;
}

const json = (csrf) => ({ 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' });
const png = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFElEQVR4nGP8z8Dwn4GBgYGJAQoAHxcCAk+Uzr4AAAAASUVORK5CYII=',
  'base64',
);

test.beforeAll(() => { phpFixture('seed'); });
test.afterAll(() => {
  try { phpFixture('cleanup'); } finally {
    if (uploadedAsset) {
      const blob = join(process.cwd(), 'var', 'vault', uploadedAsset + '.blob');
      if (existsSync(blob)) unlinkSync(blob);
    }
  }
});

test('GF1046: authenticated Vault → Composer → Scheduling → Distribution → Dashboard, tenant/role negatives', async ({ browser }) => {
  test.setTimeout(120_000);
  const sessions = [];
  try {
    const own = await login(browser, 'owner', labelA);
    sessions.push(own.context);
    const reader = await login(browser, 'reader', labelA);
    sessions.push(reader.context);
    const other = await login(browser, 'foreign', labelB);
    sessions.push(other.context);
    const a = own.context.request, b = other.context.request, read = reader.context.request;
    expect(own.data.organization.id).toBe(fixed.orgA);
    expect(other.data.organization.id).toBe(fixed.orgB);
    expect(reader.data.permissions.content_prepare).toBe(false);

    // DASHBOARD: genuine admin shell and authenticated, tenant-scoped context.
    await expect(own.page.getByRole('heading', { name: 'Resumen del espacio' })).toBeVisible();
    await expect(own.page.getByRole('heading', { name: 'Biblioteca de archivos' })).toBeVisible();
    await expect(own.page.getByRole('navigation', { name: 'Navegación administrativa' })
      .getByRole('link', { name: /Programación/ })).toBeVisible();

    // VAULT: list, real multipart creation, edit; foreign asset is indistinguishable from absent.
    const vault = await reply(await a.get('/api/admin/vault'), 200);
    expect(vault.assets.some((asset) => asset.id === fixed.assetA)).toBe(true);
    expect(vault.assets.some((asset) => asset.id === fixed.assetB)).toBe(false);
    const uploaded = await reply(await a.post('/api/admin/vault', {
      headers: { 'X-CSRF-Token': own.data.vault_upload_csrf },
      multipart: { file: { name: 'gf1046-upload.png', mimeType: 'image/png', buffer: png } },
    }), 201);
    uploadedAsset = uploaded.asset.id;
    const renamed = await reply(await a.post('/api/admin/vault/' + uploadedAsset + '/name', {
      headers: json(own.data.vault_manage_csrf), data: { name: 'gf1046-edited.png' },
    }), 200);
    expect(renamed.name).toBe('gf1046-edited.png');
    await reply(await b.get('/api/admin/vault/' + fixed.assetA), 404, fixed.assetA);
    await reply(await read.post('/api/admin/vault/' + fixed.assetA + '/name', {
      headers: json(own.data.vault_manage_csrf), data: { name: 'Forbidden.png' },
    }), 403, fixed.assetB);

    // COMPOSER today is the real review/annotation workflow, not a separate editor.
    const reviewed = await reply(await a.put('/api/admin/content-reviews/' + fixed.assetA, {
      headers: json(own.data.content_review_csrf), data: { approved: true },
    }), 200);
    expect(reviewed.approved).toBe(true);
    const revised = await reply(await a.put('/api/admin/content-reviews/' + fixed.assetA, {
      headers: json(own.data.content_review_csrf), data: { approved: false },
    }), 200);
    expect(revised.approved).toBe(false);
    await reply(await a.put('/api/admin/content-reviews/' + fixed.assetA, {
      headers: json(own.data.content_review_csrf), data: { approved: true },
    }), 200);
    await reply(await b.put('/api/admin/content-reviews/' + fixed.assetA, {
      headers: json(other.data.content_review_csrf), data: { approved: true },
    }), 404, fixed.assetA);
    await reply(await read.put('/api/admin/content-reviews/' + fixed.assetA, {
      headers: json(own.data.content_review_csrf), data: { approved: true },
    }), 403, fixed.assetB);

    // DISTRIBUTION: explicit rights, internal-only destination create/edit, no providers.
    const authorized = await reply(await a.put('/api/admin/distribution-authorizations/' + fixed.assetA, {
      headers: json(own.data.distribution_authorization_csrf), data: { authorized: true },
    }), 200);
    expect(authorized.authorized).toBe(true);
    const destination = await reply(await a.post('/api/admin/manual-destinations', {
      headers: json(own.data.manual_destination_csrf), data: { label: 'GF1046 Internal Only' },
    }), 201);
    expect(destination.provider_calls).toBe(false);
    const destinationId = destination.destination.id;
    const paused = await reply(await a.put('/api/admin/manual-destinations/' + destinationId, {
      headers: json(own.data.manual_destination_csrf), data: { active: false },
    }), 200);
    expect(paused.destination.active).toBe(false);
    const bDestinations = await reply(await b.get('/api/admin/manual-destinations'), 200);
    expect(JSON.stringify(bDestinations)).not.toContain(destinationId);
    await reply(await b.put('/api/admin/manual-destinations/' + destinationId, {
      headers: json(other.data.manual_destination_csrf), data: { active: false },
    }), 404, destinationId);
    await reply(await read.post('/api/admin/manual-destinations', {
      headers: json(own.data.manual_destination_csrf), data: { label: 'Forbidden' },
    }), 403, destinationId);

    // SCHEDULING: real preview decides server-side slots; all fixture dates above
    // and the weekly report week below are pinned to 2026-11-22/2026-11-16 UTC.
    const preview = await reply(await a.get('/api/admin/rules/weekly/preview'), 200);
    expect(preview.slots.length).toBeGreaterThan(0);
    const slot = preview.slots[0].scheduled_at_utc;
    expect(slot).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
    const made = await reply(await a.post('/api/admin/schedules', {
      headers: json(own.data.schedule_draft_csrf),
      data: { asset_id: fixed.assetA, scheduled_at_utc: slot },
    }), 201);
    expect(made.draft.status).toBe('draft');
    expect(made.publishes).toBe(false);
    const scheduleId = made.draft.id;
    const listed = await reply(await a.get('/api/admin/schedules'), 200);
    expect(listed.drafts.some((draft) => draft.id === scheduleId)).toBe(true);
    await reply(await b.post('/api/admin/schedules', {
      headers: json(other.data.schedule_draft_csrf),
      data: { asset_id: fixed.assetA, scheduled_at_utc: slot },
    }), 404, fixed.assetA);
    await reply(await read.post('/api/admin/schedules', {
      headers: json(own.data.schedule_draft_csrf),
      data: { asset_id: fixed.assetA, scheduled_at_utc: slot },
    }), 403, fixed.assetB);
    const cancelled = await reply(await a.post('/api/admin/schedules/' + scheduleId + '/cancel', {
      headers: json(own.data.schedule_draft_csrf),
    }), 200);
    expect(cancelled.publishes).toBe(false);

    // DASHBOARD: fixed-week report, foreign tenant isolation, admin-only settings
    // modification. The weekly dashboard is currently GET-only by design.
    const week = '?week=2026-11-16';
    const reportA = await reply(await a.get('/api/admin/pilot/weekly-summary' + week), 200);
    const reportB = await reply(await b.get('/api/admin/pilot/weekly-summary' + week), 200);
    expect(reportA.week_start_utc).toBe('2026-11-16');
    expect(reportB.week_start_utc).toBe('2026-11-16');
    expect(JSON.stringify(reportA)).not.toContain(fixed.assetB);
    expect(JSON.stringify(reportB)).not.toContain(fixed.assetA);
    await reply(await read.post('/api/admin/organization/name', {
      headers: json(own.data.organization_name_csrf), data: { name: 'Forbidden tenant rename' },
    }), 403);
    await reply(await a.post('/api/admin/organization/name', {
      headers: json(own.data.organization_name_csrf), data: { name: 'GF1046 Tenant A updated' },
    }), 200);
    const refreshed = await reply(await a.get('/api/admin/context'), 200);
    expect(refreshed.organization.name).toBe('GF1046 Tenant A updated');
    expect(refreshed.organization.id).toBe(fixed.orgA);
    const untouched = await reply(await b.get('/api/admin/context'), 200);
    expect(untouched.organization.name).toBe(labelB);

    // Final cross-tenant check after all writes, not just before them.
    const finalB = await reply(await b.get('/api/admin/vault'), 200);
    expect(finalB.assets.some((asset) => asset.id === fixed.assetA || asset.id === uploadedAsset)).toBe(false);
    expect(finalB.assets.some((asset) => asset.id === fixed.assetB)).toBe(true);
  } finally {
    await Promise.all(sessions.map((session) => session.close()));
  }
});
