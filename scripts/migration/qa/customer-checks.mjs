import { createRequire } from 'node:module'; import fs from 'node:fs';
const { chromium } = createRequire(import.meta.url)(process.env.PLAYWRIGHT_MODULE);
const env = Object.fromEntries(fs.readFileSync('secrets/rehearsal-customer.env','utf8').trim().split('\n').map(l=>l.split(/=(.*)/s).slice(0,2)));
const b = await chromium.launch(); const c = await b.newContext(); await c.route('**/*', r => ['localhost','127.0.0.1'].includes(new URL(r.request().url()).hostname) ? r.continue() : r.abort());
const p = await c.newPage(); await p.goto('http://localhost:8088/my-account/'); await p.fill('#username', env.RH_CUSTOMER_LOGIN); await p.fill('#password', env.RH_CUSTOMER_PASS);
await Promise.all([p.waitForNavigation(), p.click('button[name="login"]')]);
for (const u of ['/my-account/orders/', '/my-account/view-order/698962/']) {
  await p.goto('http://localhost:8088' + u); await p.waitForTimeout(1000);
  const els = await p.$$eval('a,button,input[type=submit]', es => es.map(e => (e.innerText||e.value||'').trim() + ' | ' + (e.getAttribute('href')||'')).filter(t => /invoice|print|pdf|packing/i.test(t)));
  console.log(u, JSON.stringify(els).slice(0,600));
  await p.screenshot({ path: 'migration-reports/qa/after-sync/customer' + u.replace(/\//g,'_') + '.png', fullPage: true });
}
const link = await p.$('a[href*="type=download_invoice"]');
if (link) { const h = await link.getAttribute('href'); const r = await c.request.get(new URL(h, 'http://localhost:8088').href); console.log('invoice GET', r.status(), r.headers()['content-type'], (await r.body()).length, 'bytes'); }
// Second test customer: legacy phpass hash must also log in
const c2 = await b.newContext(); await c2.route('**/*', r => ['localhost','127.0.0.1'].includes(new URL(r.request().url()).hostname) ? r.continue() : r.abort());
const p2 = await c2.newPage(); await p2.goto('http://localhost:8088/my-account/'); await p2.fill('#username', env.RH_CUSTOMER_B_LOGIN); await p2.fill('#password', env.RH_CUSTOMER_B_PASS);
await Promise.all([p2.waitForNavigation(), p2.click('button[name="login"]')]);
console.log('customer B (phpass) logged in:', !!(await p2.$('a[href*="customer-logout"]')));
await p2.goto('http://localhost:8088/my-account/orders/'); console.log('customer B orders:', JSON.stringify(await p2.$$eval('a[href*="view-order"]', es => [...new Set(es.map(e=>e.getAttribute('href').match(/view-order\/(\d+)/)[1]))])));
// Wrong password is rejected
const c3 = await b.newContext(); const p3 = await c3.newPage(); await p3.goto('http://localhost:8088/my-account/'); await p3.fill('#username', env.RH_CUSTOMER_LOGIN); await p3.fill('#password', 'wrong-password');
await Promise.all([p3.waitForNavigation(), p3.click('button[name="login"]')]); console.log('wrong password rejected:', !(await p3.$('a[href*="customer-logout"]')));
await b.close();
