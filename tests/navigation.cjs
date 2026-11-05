// Run with: node --test tests/navigation.cjs
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const script = read('assets/navigation.js');
function browser(url, base) {
  let current = new URL(url);
  const location = {};
  for (const key of ['hash','pathname','search']) Object.defineProperty(location,key,{get:()=>current[key]});
  const element = {href:base};
  const document = {baseURI:base,querySelector:()=>element,querySelectorAll:()=>[],addEventListener:()=>{}};
  const window = {dispatchEvent:()=>{}};
  const history = {pushState(_state,_unused,target){current=new URL(target);}};
  const Event = function(name){this.type=name;};
  vm.runInNewContext(script, {document,location,window,history,Event,URL,URLSearchParams});
  return {window,href:()=>current.href};
}
test('named admin and public pages work under a subdirectory', () => {
  const b=browser('https://example.test/shop/admin/products.php','https://example.test/shop/');
  assert.equal(b.window.currentRoute(),'admin/products');
  b.window.navigate('login');assert.equal(b.href(),'https://example.test/shop/website/login.php');
  assert.equal(b.window.currentRoute(),'login');
  b.window.navigate('admin/pages');assert.equal(b.href(),'https://example.test/shop/admin/pages.php');
  assert.equal(b.window.currentRoute(),'admin/pages');
});
test('legacy hashes, CMS slugs, product IDs and email tokens round-trip', () => {
  const b=browser('https://example.test/shop/#admin/settings','https://example.test/shop/');
  assert.equal(b.window.currentRoute(),'admin/settings');
  for(const route of ['custom-page','product/42','reset/abcdef','verify-email/abcdef']) {
    b.window.navigate(route);assert.equal(b.window.currentRoute(),route);
  }
  assert.match(b.href(),/website\/verify-email\.php\?token=abcdef$/);
});
test('all named entry points and PWA start pages exist', () => {
  for(const name of 'index overview products pages slides settings marketing socials members orders purchases refunds rules payouts audit staff security'.split(' '))assert.ok(fs.existsSync(path.join(root,'admin',name+'.php')),name);
  for(const name of 'index about products product community contact cart login register forgot reset verify-email page'.split(' '))assert.ok(fs.existsSync(path.join(root,'website',name+'.php')),name);
  assert.equal(JSON.parse(read('manifest.webmanifest')).start_url,'./website/index.php');
  assert.equal(JSON.parse(read('admin.webmanifest')).start_url,'./admin/index.php');
  const html=read('index.html');
  assert.ok(html.indexOf('website/pages.js') < html.indexOf('admin/panel.js'));
  assert.ok(html.indexOf('admin/panel.js') < html.indexOf('assets/extended.js'));
  const sw=read('sw.js');
  assert.ok(sw.includes('./website/pages.js') && sw.includes('./admin/panel.js'));
  assert.ok(sw.includes('./website/index.php') && sw.includes('./admin/index.php'));
});
