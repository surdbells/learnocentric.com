/**
 * Cloudflare Pages serves `index.html`, but an Angular SSR build emits the
 * client shell as `index.csr.html`. Since nothing is prerendered, that file IS
 * the SPA entry — copy it to `index.html` so Pages (and the _redirects SPA
 * fallback) can serve it. Runs after `ng build` in `npm run build:pages`.
 */
const fs = require('fs');
const path = require('path');

const dir = path.resolve(__dirname, '..', 'dist', 'learno-client', 'browser');
const csr = path.join(dir, 'index.csr.html');
const html = path.join(dir, 'index.html');

if (fs.existsSync(html)) {
  console.log('[pages-index] index.html already present — nothing to do.');
} else if (fs.existsSync(csr)) {
  fs.copyFileSync(csr, html);
  console.log('[pages-index] copied index.csr.html -> index.html');
} else {
  console.error('[pages-index] ERROR: neither index.html nor index.csr.html found in', dir);
  process.exit(1);
}
