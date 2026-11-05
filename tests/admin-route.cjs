// Run with: node --test tests/admin-route.cjs
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

test('admin route class follows initial URL and subsequent navigation', () => {
  const classes = new Set();
  const location = {hash: '#admin'};
  const handlers = {};
  const document = {documentElement: {classList: {toggle(name, on) {
    if (on) classes.add(name); else classes.delete(name);
  }}}};
  const window = {addEventListener(name, fn) {handlers[name] = fn;}};
  vm.runInNewContext(read('assets/admin-route.js'), {document, location, window});
  assert.equal(classes.has('admin-route'), true);
  for (const [hash, expected] of [['#admin/products', true], ['#products', false], ['#administrator', false], ['#admin', true]]) {
    location.hash = hash;
    handlers.hashchange();
    assert.equal(classes.has('admin-route'), expected, hash);
  }
});

test('admin header styles and early script are included in the cached shell', () => {
  const html = read('index.html');
  const css = read('assets/style.css');
  const sw = read('sw.js');
  assert.ok(html.includes('assets/admin-route.js'));
  assert.ok(html.indexOf('assets/admin-route.js') < html.indexOf('assets/style.css'));
  assert.match(css, /html\.admin-route \.announcement\s*,\s*html\.admin-route \.header\s*\{\s*display\s*:\s*none\s*\}/);
  assert.ok(sw.includes('./assets/admin-route.js'));
});
