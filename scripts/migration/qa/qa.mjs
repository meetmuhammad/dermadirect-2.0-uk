// Browser QA for the migration rehearsal (Playwright, Chromium, headless).
//   node scripts/migration/qa/qa.mjs --label=before [--customer]   (after: --label=after --compare=before)
// Every non-localhost request is aborted in the browser and recorded, so no analytics, marketing,
// payment or CDN call can leave the machine. Checkout is never submitted.
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE);
const args = Object.fromEntries(process.argv.slice(2).map((a) => { const [k, v] = a.replace(/^--/, '').split('='); return [k, v ?? true]; }));
const BASE = args.base || 'http://localhost:8088';
const ROOT = path.resolve(args.out || 'migration-reports/qa');
const OUT = path.join(ROOT, args.label);
fs.mkdirSync(OUT, { recursive: true });
const ADMIN_ENV = args['admin-env'] || 'secrets/rehearsal-admin.env';
const env = (f) => Object.fromEntries(fs.readFileSync(f, 'utf8').trim().split('\n').map((l) => l.split(/=(.*)/s).slice(0, 2)));

const PUBLIC = [
  ['home', '/'], ['shop', '/shop/'], ['category-skin-boosters', '/product-category/skin-boosters/'],
  ['category-dermal-fillers', '/product-category/dermal-fillers/'], ['product-simple', '/product/teosyal-rha-kiss-volume-2x1ml/'],
  ['product-variable', '/product/disposable-nitrile-gloves/'], ['product-regenovue', '/product/regenovue-aqua-shine-plus/'],
  ['page-about', '/about/'], ['page-contact', '/contact/'], ['page-training', '/training-partner/'], ['blog', '/blog/'],
  ['post-pdrn', '/pdrn-vs-pn-the-real-difference-explained-for-aesthetic-practitioners-2/'], ['my-account', '/my-account/'],
];
const ADMIN = [
  ['admin-dashboard', '/wp/wp-admin/'], ['admin-orders', '/wp/wp-admin/edit.php?post_type=shop_order'],
  ['admin-products', '/wp/wp-admin/edit.php?post_type=product'], ['admin-menus', '/wp/wp-admin/nav-menus.php'],
  ['admin-acf', '/wp/wp-admin/edit.php?post_type=acf-field-group'], ['admin-users', '/wp/wp-admin/users.php'],
  ['admin-media', '/wp/wp-admin/upload.php?mode=list'], ['admin-payments', '/wp/wp-admin/admin.php?page=wc-settings&tab=checkout'],
  ['admin-plugins', '/wp/wp-admin/plugins.php'], ['admin-home-edit', '/wp/wp-admin/post.php?post=' + (args.homeid || '696282') + '&action=edit'],
];

const report = { label: args.label, base: BASE, at: new Date().toISOString(), pages: {}, blockedExternal: {}, links: {} };

// --map=<origin>: serve BASE's host from a local origin (simulated hosted site). Nothing else leaves.
const MAP = args.map || null;
const BASEHOST = new URL(BASE).hostname;
async function instrument(context) {
  await context.route('**/*', async (route) => {
    const req = route.request();
    const u = new URL(req.url());
    if (MAP && u.hostname === BASEHOST) {
      const resp = await route.fetch({ url: MAP + u.pathname + u.search, headers: await req.allHeaders(), maxRedirects: 0 }).catch(() => null);
      return resp ? route.fulfill({ response: resp }) : route.abort('failed');
    }
    if (['localhost', '127.0.0.1'].includes(u.hostname)) return route.continue();
    report.blockedExternal[u.hostname] = (report.blockedExternal[u.hostname] || 0) + 1;
    return route.abort('blockedbyclient');
  });
}

async function visit(page, name, url, { viewport = 'desktop', shot = true } = {}) {
  const rec = { url, viewport, console: [], failed: [], status: null };
  const onConsole = (m) => { if (m.type() === 'error' && !/ERR_BLOCKED_BY_CLIENT|net::ERR_FAILED/.test(m.text())) rec.console.push(m.text().slice(0, 300)); };
  const onFail = (r) => { const u = r.url(); if (u.startsWith(BASE) && !/ERR_BLOCKED_BY_CLIENT/.test(r.failure()?.errorText || '')) rec.failed.push(`${r.failure()?.errorText} ${u}`); };
  const onResp = (r) => { if (r.url().startsWith(BASE) && r.status() >= 400) rec.failed.push(`${r.status()} ${r.url()}`); };
  page.on('console', onConsole); page.on('requestfailed', onFail); page.on('response', onResp);
  const t0 = Date.now();
  const resp = await page.goto(BASE + url, { waitUntil: 'load', timeout: 90000 }).catch((e) => ({ status: () => 'ERR ' + e.message.slice(0, 120) }));
  rec.status = resp?.status?.() ?? null;
  await page.waitForTimeout(800);
  // lazy images: scroll through the page so they load, then measure
  await page.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 700) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 120)); } window.scrollTo(0, 0); });
  await page.waitForTimeout(600);
  rec.ms = Date.now() - t0;
  Object.assign(rec, await page.evaluate(() => {
    const vis = (el) => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
    const imgs = [...document.images];
    // Fingerprint scope excludes the theme's random "recommended products" block (orderby=rand,
    // app/View/Composers/ProductSingle.php) that follows the reviews wrapper on product pages.
    const scope = document.body.cloneNode(true);
    const rev = scope.querySelector('#product_reviews_section_wrapper');
    if (rev) { let n = rev.nextElementSibling; while (n) { const x = n.nextElementSibling; n.remove(); n = x; } }
    const main = scope.querySelector('main') || scope;
    return {
      title: document.title,
      criticalError: /critical error|There has been a critical error/i.test(document.body.innerText),
      images: imgs.length,
      brokenImages: imgs.filter((i) => i.complete && i.naturalWidth === 0 && vis(i) && i.currentSrc && !/^data:/.test(i.currentSrc)).map((i) => i.currentSrc).slice(0, 20),
      imgSrcs: [...new Set([...scope.querySelectorAll('img')].map((i) => (i.getAttribute('src') || '').replace(/\?.*$/, '')).filter(Boolean))].sort(),
      navLinks: [...scope.querySelectorAll('header a, nav a')].map((a) => (a.textContent.trim().replace(/\s+/g, ' ') + ' -> ' + a.getAttribute('href'))).filter((x) => x.length > 4),
      links: [...new Set([...document.querySelectorAll('a[href]')].map((a) => a.href).filter((h) => h.startsWith(location.origin)))],
      text: main.innerText.replace(/\s+/g, ' ').replace(/\d+\s*hrs\s*\d+\s*mins\s*\d+\s*sec/gi, 'Nhrs Nmins Nsec').trim().slice(0, 20000),
      acfBlocks: document.querySelectorAll('[class*="wp-block-acf"], [data-block], .acf-block').length,
      variations: document.querySelectorAll('form.variations_form select').length,
      tabs: [...document.querySelectorAll('ul.subsubsub li a')].map((a) => a.innerText.replace(/\s+/g, ' ').trim()),
      displaying: (document.querySelector('.tablenav.top .displaying-num') || {}).innerText || null,
      gallery: document.querySelectorAll('.woocommerce-product-gallery__image, .swiper-slide img').length,
    };
  }));
  if (shot) await page.screenshot({ path: path.join(OUT, `${name}-${viewport}.png`), fullPage: true });
  page.off('console', onConsole); page.off('requestfailed', onFail); page.off('response', onResp);
  report.pages[`${name}-${viewport}`] = rec;
  const flag = rec.criticalError || rec.brokenImages.length || rec.failed.length || !(rec.status >= 200 && rec.status < 400) ? '  <-- CHECK' : '';
  console.log(`${String(rec.status).padEnd(4)} ${String(rec.ms).padStart(6)}ms ${name}-${viewport} imgs=${rec.images} broken=${rec.brokenImages.length} failed=${rec.failed.length} console=${rec.console.length}${flag}`);
  return rec;
}

async function login(page, user, pass) {
  await page.goto(BASE + '/wp/wp-login.php', { waitUntil: 'load' });
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#wp-submit')]);
  return !/wp-login\.php/.test(page.url()) || !(await page.$('#login_error'));
}

const browser = await chromium.launch();
try {
  // Public pages, desktop and mobile
  for (const [vp, size] of [['desktop', { width: 1440, height: 900 }], ['mobile', { width: 390, height: 844 }]]) {
    const ctx = await browser.newContext({ viewport: size, isMobile: vp === 'mobile' });
    await instrument(ctx);
    const page = await ctx.newPage();
    for (const [name, url] of PUBLIC) {
      if (vp === 'mobile' && !['home', 'category-skin-boosters', 'product-variable', 'product-regenovue', 'page-about'].includes(name)) continue;
      await visit(page, name, url, { viewport: vp });
    }
    if (vp === 'mobile') {
      const toggle = await page.goto(BASE + '/').then(() => page.$('button[aria-controls], .menu-toggle, .hamburger, [class*="burger"], [aria-label*="menu" i]'));
      if (toggle) { await toggle.click().catch(() => {}); await page.waitForTimeout(700); await page.screenshot({ path: path.join(OUT, 'home-mobile-menu-open.png') }); }
      report.mobileMenuToggle = !!toggle;
    }
    // Cart + protected checkout (never submitted)
    if (vp === 'desktop') {
      await visit(page, 'add-to-cart', '/?add-to-cart=' + (args.cartproduct || '694749'), { shot: false });
      const cart = await visit(page, 'cart', '/cart/');
      const co = await visit(page, 'checkout', '/checkout/');
      report.checkout = await page.evaluate(() => ({
        paymentMethods: [...document.querySelectorAll('input[name="payment_method"]')].map((i) => i.value),
        placeOrderEnabled: !!document.querySelector('#place_order:not([disabled])'),
        fields: document.querySelectorAll('form.checkout input, form.checkout select').length,
      }));
      report.cartHasItems = !/empty/i.test(cart.text.slice(0, 400));
    }
    await ctx.close();
  }
  // Admin
  const a = env(ADMIN_ENV);
  const actx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await instrument(actx);
  const ap = await actx.newPage();
  report.adminLogin = await login(ap, a.RH_ADMIN_LOGIN, a.RH_ADMIN_PASS);
  for (const [name, url] of ADMIN) await visit(ap, name, url);
  if (args.orderid) await visit(ap, 'admin-order-' + args.orderid, `/wp/wp-admin/post.php?post=${args.orderid}&action=edit`);
  await actx.close();
  // After cleanup: the synthetic test customers must no longer be able to log in.
  if (args['expect-no-test-customers']) {
    const c = env('secrets/rehearsal-customer.env');
    const x = await browser.newContext(); await instrument(x); const xp = await x.newPage();
    await xp.goto(BASE + '/my-account/', { waitUntil: 'load' });
    await xp.fill('#username', c.RH_CUSTOMER_LOGIN); await xp.fill('#password', c.RH_CUSTOMER_PASS);
    await Promise.all([xp.waitForNavigation({ waitUntil: 'load' }), xp.click('button[name="login"]')]);
    report.testCustomerRejected = !(await xp.$('a[href*="customer-logout"]'));
    await x.close();
  }
  // Customer (after sync): test account created on the source copy with a known password
  if (args.customer) {
    const c = env(args['customer-env'] || 'secrets/rehearsal-customer.env');
    const cctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    await instrument(cctx);
    const cp = await cctx.newPage();
    await cp.goto(BASE + '/my-account/', { waitUntil: 'load' });
    await cp.fill('#username', c.RH_CUSTOMER_LOGIN);
    await cp.fill('#password', c.RH_CUSTOMER_PASS);
    await Promise.all([cp.waitForNavigation({ waitUntil: 'load' }), cp.click('button[name="login"]')]);
    report.customerLogin = !!(await cp.$('.woocommerce-MyAccount-navigation, a[href*="customer-logout"]'));
    const orders = await visit(cp, 'customer-orders', '/my-account/orders/');
    report.customerOrderLinks = orders.links.filter((l) => /view-order/.test(l));
    if (report.customerOrderLinks[0]) {
      const vo = await visit(cp, 'customer-view-order', new URL(report.customerOrderLinks[0]).pathname);
      report.invoiceLinks = vo.links.filter((l) => /invoice|print|pdf/i.test(l));
      if (report.invoiceLinks[0]) {
        const r = await cctx.request.get(report.invoiceLinks[0]);
        report.invoiceDownload = { status: r.status(), type: r.headers()['content-type'], bytes: (await r.body()).length };
      }
    }
    await cctx.close();
  }
  // Same-origin link check (unique links gathered from public pages)
  const links = [...new Set(Object.values(report.pages).flatMap((p) => p.links || []))].filter((l) => !/wp-admin|logout|add-to-cart|\/feed|wp-login|\.(zip|pdf)$/.test(l)).slice(0, 250);
  const lctx = await browser.newContext();
  for (const l of links) {
    const target = MAP ? l.replace(new URL(BASE).origin, MAP) : l;
    const r = await lctx.request.get(target, { maxRedirects: MAP ? 0 : 5, timeout: 60000 }).catch((e) => ({ status: () => 'ERR' }));
    report.links[l] = r.status();
  }
  await lctx.close();
} finally {
  await browser.close();
}
const badLinks = Object.entries(report.links).filter(([, s]) => !(s >= 200 && s < 400));
report.summary = {
  pages: Object.keys(report.pages).length,
  non2xx: Object.entries(report.pages).filter(([, p]) => !(p.status >= 200 && p.status < 400)).map(([k, p]) => `${k}:${p.status}`),
  criticalErrors: Object.entries(report.pages).filter(([, p]) => p.criticalError).map(([k]) => k),
  brokenImages: Object.values(report.pages).reduce((n, p) => n + p.brokenImages.length, 0),
  failedRequests: Object.values(report.pages).reduce((n, p) => n + p.failed.length, 0),
  consoleErrors: Object.values(report.pages).reduce((n, p) => n + p.console.length, 0),
  linksChecked: Object.keys(report.links).length, badLinks,
};
fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
console.log(JSON.stringify(report.summary, null, 1), '\norders tabs', JSON.stringify(report.pages['admin-orders-desktop']?.tabs), 'list', report.pages['admin-orders-desktop']?.displaying, '\ntestCustomerRejected', report.testCustomerRejected, '\nadminLogin', report.adminLogin, 'checkout', JSON.stringify(report.checkout), 'customerLogin', report.customerLogin ?? '-');

// Before/after comparison of rendered content (same pages, same viewport)
if (args.compare) {
  const prev = JSON.parse(fs.readFileSync(path.join(ROOT, args.compare, 'report.json'), 'utf8'));
  const diffs = {};
  for (const [k, p] of Object.entries(report.pages)) {
    const b = prev.pages[k];
    if (!b || k.startsWith('admin-') || k.startsWith('customer-') || ['cart-desktop', 'checkout-desktop', 'add-to-cart-desktop'].includes(k)) continue;
    const d = {};
    if (b.title !== p.title) d.title = [b.title, p.title];
    if (b.text !== p.text) d.text = 'differs';
    const missImg = b.imgSrcs.filter((s) => !p.imgSrcs.includes(s)); const newImg = p.imgSrcs.filter((s) => !b.imgSrcs.includes(s));
    if (missImg.length || newImg.length) d.images = { missing: missImg, added: newImg };
    if (JSON.stringify(b.navLinks) !== JSON.stringify(p.navLinks)) d.nav = { before: b.navLinks.length, after: p.navLinks.length };
    if (b.brokenImages.length !== p.brokenImages.length) d.broken = [b.brokenImages.length, p.brokenImages.length];
    if (Object.keys(d).length) diffs[k] = d;
  }
  fs.writeFileSync(path.join(OUT, 'compare.json'), JSON.stringify(diffs, null, 2));
  console.log('COMPARE vs', args.compare, Object.keys(diffs).length ? JSON.stringify(diffs, null, 1).slice(0, 4000) : 'IDENTICAL rendered content on all compared public pages');
}
