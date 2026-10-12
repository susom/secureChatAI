// Token-policy UI E2E: the settings a REDCap admin actually touches, and the smoke-test page.
//
//   docker exec redcap_2023_1_web php .../tests/e2e/fixture.php setup      # test config + admin user
//   docker exec -d redcap_2023_1_web php -S 127.0.0.1:8099 .../tests/e2e/mock_aihub.php
//   NODE_PATH=../proj_mica_v9.9.9/node_modules node tests/e2e/token-policy.js
//   docker exec redcap_2023_1_web php .../tests/e2e/fixture.php teardown
//
// Desktop (1400x950) and iPhone 13. Signs in as the fixture's throwaway SUPER user: system settings
// and the super-users-only project minimum are admin-only by design.
const { chromium, devices } = require('playwright');
const fs = require('fs');
const { execSync } = require('child_process');

const HOST_RULES = process.env.SCA_HOST_RULES || 'MAP redcap.local 127.0.0.1';
const BASE = process.env.SCA_BASE || 'http://redcap.local';
const V = 'redcap_v17.2.3';
const USER = 'e2e_sca_admin';
const PASS = 'E2eScaAdmin!2026';
const PID = 270;
const TESTS_URL = `${BASE}/${V}/ExternalModules/?prefix=secure_chat_ai&page=pages%2Ftests`;
const SHOTS = __dirname + '/shots';
if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

// Query over stdin: no shell quoting of the SQL, and the container supplies its own credentials.
const sql = (q) => execSync(
  `docker exec -i redcap_2023_1_db sh -c 'mysql -uredcap -p"$MYSQL_PASSWORD" redcap -N 2>/dev/null'`,
  { input: q },
).toString().trim();
const setting = (key, pid = null) => sql(
  `select value from redcap_external_module_settings where external_module_id = 39 and \`key\` = '${key}' and project_id ${pid ? '= ' + pid : 'is null'}`,
);

let pass = 0, fail = 0;
const check = (name, ok, detail = '') => {
  ok ? pass++ : fail++;
  console.log(`  ${ok ? '[ok]  ' : '[FAIL]'} ${name}${!ok && detail ? ' — ' + detail : ''}`);
};

async function login(p) {
  await p.goto(`${BASE}/${V}/index.php`, { waitUntil: 'domcontentloaded' });
  await p.locator('input[name="username"]').first().fill(USER);
  await p.locator('input[name="password"]').first().fill(PASS);
  await p.locator('#login_btn').first().click();
  await p.waitForLoadState('domcontentloaded');
  return !(await p.locator('input[name="password"]').count());
}

async function openConfig(p, url, waitFor) {
  await p.goto(url, { waitUntil: 'domcontentloaded' });
  const row = p.locator('tr[data-module="secure_chat_ai"]').first();
  await row.locator('button:has-text("Configure")').first().click();
  const modal = p.locator('#external-modules-configure-modal');
  await modal.waitFor({ state: 'visible', timeout: 15000 });
  await modal.locator(waitFor).first().waitFor({ state: 'attached', timeout: 15000 });
  return modal;
}

// Against the device's screen width, not innerWidth: mobile Chrome widens the layout viewport to
// fit overflowing content, so innerWidth grows with the very overflow this is meant to catch.
async function noSideScroll(p) {
  return p.evaluate(() => document.documentElement.scrollWidth <= screen.width + 1);
}

async function systemConfig(p, label) {
  const modal = await openConfig(p, `${BASE}/${V}/ExternalModules/manager/control_center.php`, 'input[name="aihub-token-high-threshold"]');
  const std = modal.locator('input[name="aihub-token-standard"]');
  const high = modal.locator('input[name="aihub-token-high-threshold"]');
  check(`S1 ${label}: standard token field is masked`, (await std.getAttribute('type')) === 'password');
  check(`S2 ${label}: high-threshold token field is masked`, (await high.getAttribute('type')) === 'password');
  const text = await modal.innerText();
  check(`S3 ${label}: both token labels shown`, text.includes('AI Hub Token (standard)') && text.includes('AI Hub Token (high-threshold)'));

  const policies = modal.locator('select[name^="token-policy____"]');
  const values = await policies.evaluateAll((els) => els.map((e) => e.value));
  check(`S4 ${label}: each model row has a Token Policy dropdown with its saved value`,
    JSON.stringify(values) === JSON.stringify(['standard', 'standard', 'high', 'high']), JSON.stringify(values));
  const options = await policies.first().locator('option').evaluateAll((els) => els.map((e) => `${e.value}=${e.textContent.trim()}`));
  check(`S5 ${label}: dropdown offers standard and high-threshold`,
    options.includes('standard=standard') && options.includes('high=high-threshold'), JSON.stringify(options));
  const rowToken = modal.locator('input[name^="api-token____"]').first();
  check(`S6 ${label}: row API Token is labelled as an optional override`, text.includes('optional override'));
  check(`S6b ${label}: row API Token is no longer required`, !(await rowToken.evaluate((e) => e.closest('tr').innerHTML.includes('requiredParm') || e.required)));

  // Refused save: high rows with no high token and no row override.
  const before = setting('aihub-token-high-threshold');
  await high.fill('');
  const dialogs = [];
  p.on('dialog', (d) => { dialogs.push(d.message()); d.dismiss().catch(() => {}); });
  await modal.locator('button.save').click();
  await p.waitForTimeout(1500);
  const shown = dialogs.join(' ') + ' ' + (await p.locator('body').innerText());
  check(`S7 ${label}: save refused when a high row has no token`, shown.includes('uses the high policy but has no API token'), shown.slice(0, 200));
  check(`S8 ${label}: refusal names the setting to fill`, shown.includes('AI Hub Token (high-threshold)'));
  check(`S9 ${label}: nothing was saved`, setting('aihub-token-high-threshold') === before);
  await p.screenshot({ path: `${SHOTS}/system-refused-${label}.png`, fullPage: false });

  // Valid save round-trip through the real dialog.
  await p.goto('about:blank');
  const modal2 = await openConfig(p, `${BASE}/${V}/ExternalModules/manager/control_center.php`, 'input[name="aihub-token-high-threshold"]');
  await modal2.locator('button.save').click();
  await modal2.waitFor({ state: 'hidden', timeout: 15000 }).catch(() => {});
  check(`S10 ${label}: valid save goes through`, !(await modal2.isVisible()));
  // A successful save reloads the page; let that finish before navigating away.
  await p.waitForLoadState('networkidle').catch(() => {});
  await p.waitForTimeout(1000);
  check(`S11 ${label}: tokens and policies survive a UI save`,
    setting('aihub-token-high-threshold') === 'e2e-high-key' && setting('aihub-token-standard') === 'e2e-std-key'
    && setting('token-policy') === '["standard","standard","high","high"]', setting('token-policy'));
}

async function projectConfig(p, label) {
  const modal = await openConfig(p, `${BASE}/${V}/ExternalModules/manager/project.php?pid=${PID}`, 'select[name="token-policy-default"]');
  const sel = modal.locator('select[name="token-policy-default"]');
  const options = await sel.locator('option').evaluateAll((els) => els.map((e) => e.value));
  check(`P1 ${label}: project has a Minimum Token Policy dropdown`, options.includes('standard') && options.includes('high'), JSON.stringify(options));
  check(`P2 ${label}: help text says a call can never go below it`, (await modal.innerText()).includes('never lower it'));
  await sel.selectOption('high');
  await modal.locator('button.save').click();
  await modal.waitFor({ state: 'hidden', timeout: 15000 }).catch(() => {});
  await p.waitForLoadState('networkidle').catch(() => {});
  check(`P3 ${label}: minimum saved through the UI`, setting('token-policy-default', PID) === 'high', setting('token-policy-default', PID));
  sql(`delete from redcap_external_module_settings where external_module_id = 39 and project_id = ${PID} and \`key\` = 'token-policy-default'`);
}

async function testsPage(p, label) {
  await p.goto(TESTS_URL, { waitUntil: 'domcontentloaded', timeout: 120000 });
  const rows = await p.locator('tbody tr').evaluateAll((trs) => trs.map((tr) => tr.innerText.replace(/\s+/g, ' ')));
  check(`T1 ${label}: all four model rows ran`, rows.length === 4, JSON.stringify(rows));
  const want = [
    ['gpt-4-1', 'standard', 'path=/standard/gpt-4-1 key=e2e-legacy-row-key'],
    ['gpt-5-4', 'standard', 'path=/standard/gpt-5-4 key=e2e-std-key'],
    ['gpt-5-4', 'high-threshold', 'path=/high/gpt-5-4 key=e2e-high-key'],
    ['gpt-4-1-nano', 'high-threshold', 'path=/high/gpt-4-1-nano key=e2e-high-key'],
  ];
  for (const [alias, badge, tag] of want) {
    const hit = rows.some((r) => r.startsWith(alias + ' ') && r.includes(badge) && r.includes(tag) && r.includes('✅'));
    check(`T2 ${label}: ${alias} (${badge}) passed on its own key`, hit);
  }
  check(`T3 ${label}: 4 / 4 passed`, (await p.locator('.summary-card').innerText()).includes('4 / 4'));
  check(`T4 ${label}: no sideways scroll`, await noSideScroll(p),
    String(await p.evaluate(() => document.documentElement.scrollWidth + ' > ' + screen.width)));
  const boxes = await p.locator('.table-responsive').evaluateAll((els) => els.map((e) => [e.scrollWidth, e.clientWidth]));
  check(`T4b ${label}: model tables fit their box (no inner sideways scroll)`, boxes.every(([sw, cw]) => sw <= cw + 1), JSON.stringify(boxes));
  await p.screenshot({ path: `${SHOTS}/tests-all-${label}.png`, fullPage: true });

  await p.locator('a[data-policy="high"]').click();
  await p.waitForLoadState('domcontentloaded');
  const highRows = await p.locator('tbody tr').evaluateAll((trs) => trs.map((tr) => tr.innerText));
  check(`T5 ${label}: High-threshold filter runs only the high set`, highRows.length === 2 && highRows.every((r) => r.includes('high-threshold')), JSON.stringify(highRows));
  check(`T6 ${label}: active filter is marked`, (await p.locator('a[data-policy="high"]').getAttribute('aria-current')) === 'true');
  await p.screenshot({ path: `${SHOTS}/tests-high-${label}.png`, fullPage: true });

  await p.locator('a[data-policy="standard"]').click();
  await p.waitForLoadState('domcontentloaded');
  const stdRows = await p.locator('tbody tr').evaluateAll((trs) => trs.map((tr) => tr.innerText));
  check(`T7 ${label}: Standard filter runs only the standard set`, stdRows.length === 2 && stdRows.every((r) => !r.includes('high-threshold')), JSON.stringify(stdRows));
  if (label === 'mobile') {
    const tap = await p.locator('a[data-policy]').evaluateAll((els) => els.map((e) => Math.round(e.getBoundingClientRect().height)));
    check(`T8 ${label}: filter buttons are tappable (>= 44px)`, tap.every((h) => h >= 44), JSON.stringify(tap));
  }
}

async function run(mobile) {
  const label = mobile ? 'mobile' : 'desktop';
  console.log(`\n=== ${label} ===`);
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });
  const ctx = await browser.newContext(mobile ? { ...devices['iPhone 13'] } : { viewport: { width: 1400, height: 950 } });
  const p = await ctx.newPage();
  try {
    check(`L1 ${label}: admin can sign in`, await login(p));
    if (!mobile) {
      // The config dialogs are REDCap's own; checked once, on desktop.
      await systemConfig(p, label);
      await projectConfig(p, label);
    }
    await testsPage(p, label);
  } catch (e) {
    check(`${label}: run completed`, false, String(e).slice(0, 300));
    await p.screenshot({ path: `${SHOTS}/error-${label}.png` }).catch(() => {});
  }
  await browser.close();
}

(async () => {
  await run(false);
  await run(true);
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
