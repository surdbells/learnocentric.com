/**
 * One-off: capture real product screenshots for the public marketing site.
 * Drives the installed Chrome via puppeteer-core against the local dev stack,
 * injecting a real login token per role. Outputs to public/images/marketing/.
 *
 *   node scripts/marketing-shots.cjs
 */
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const API = 'http://127.0.0.1:8090/backend';
const APP = 'http://localhost:4200';
const OUT = path.join(__dirname, '..', 'public', 'images', 'marketing');
const MODULES = ['assessments', 'worksheets', 'portfolio', 'analytics', 'live_classes', 'interventions', 'safeguarding', 'messaging', 'reports'];

const SHOTS = [
  {file: 'learner-dashboard.png',  email: 'student@gmail.com',   url: '/student/main' },
  {file: 'learner-subject.png',    email: 'student@gmail.com',   url: '/student/academics/subjects/1' },
  {file: 'learner-progress.png',   email: 'student@gmail.com',   url: '/student/academics/progress-feedback' },
  {file: 'teacher-dashboard.png',  email: 'teacher@gmail.com',   url: '/teacher/main' },
  {file: 'admin-dashboard.png',    email: 'school@gmail.com',    url: '/admin/main' },
  {file: 'admin-analytics.png',    email: 'school@gmail.com',    url: '/admin/academics/analytics' },
  {file: 'platform-analytics.png', email: 'surdbells@gmail.com', url: '/super-admin/main' },
];

async function login(email) {
  const r = await fetch(`${API}/auth/login`, {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({email, password: 'Password@1'}),
  });
  const d = await r.json();
  if (!d.token) throw new Error('login failed for ' + email);
  return d;
}

(async () => {
  fs.mkdirSync(OUT, {recursive: true});
  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--hide-scrollbars', '--force-color-profile=srgb'],
    defaultViewport: {width: 1440, height: 900, deviceScaleFactor: 2},
  });
  const page = await browser.newPage();
  const results = [];

  for (const shot of SHOTS) {
    try {
      const {token, user} = await login(shot.email);
      await page.goto(APP + '/', {waitUntil: 'domcontentloaded'});
      await page.evaluate((t, u, m) => {
        localStorage.setItem('auth_token', t);
        localStorage.setItem('auth_user', JSON.stringify(u));
        localStorage.setItem('granted_modules', JSON.stringify(m));
      }, token, user, MODULES);

      await page.goto(APP + shot.url, {waitUntil: 'networkidle2', timeout: 45000});
      // Wait for the app shell (sidebar) then let charts/data settle.
      await page.waitForSelector('.sb-nav', {timeout: 20000}).catch(() => {});
      await new Promise((r) => setTimeout(r, 3500));

      await page.screenshot({path: path.join(OUT, shot.file), fullPage: false});
      results.push(`OK   ${shot.file}`);
    } catch (e) {
      results.push(`FAIL ${shot.file} — ${e.message}`);
    }
  }

  await browser.close();
  console.log(results.join('\n'));
})();
