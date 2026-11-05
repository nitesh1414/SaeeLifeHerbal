const CACHE='saee-shell-v8';
const SHELL=['./','./index.html','./website/index.php','./admin/index.php','./manifest.webmanifest','./admin.webmanifest','./assets/style.css','./assets/admin-route.js','./assets/navigation.js','./website/pages.js','./admin/panel.js','./assets/app.js','./assets/extended.js','./assets/icon-512.png','./assets/logo.png','./assets/hero-farm.jpg','./assets/product-bottle.jpg','./assets/products.jpg'];
self.addEventListener('install',e=>e.waitUntil(caches.open(CACHE).then(c=>c.addAll(SHELL))));
self.addEventListener('activate',e=>e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k))))));
self.addEventListener('fetch',e=>{
  if(e.request.method!=='GET'||new URL(e.request.url).pathname.includes('/api/'))return;
  e.respondWith(fetch(e.request).catch(async()=>{
    if(e.request.mode==='navigate'){
      const scope=new URL(self.registration.scope).pathname;
      const relative=new URL(e.request.url).pathname.slice(scope.length);
      const shell=relative.startsWith('admin/')?'./admin/index.php':relative.startsWith('website/')?'./website/index.php':'./index.html';
      return (await caches.match(shell)) || (await caches.match('./index.html'));
    }
    return caches.match(e.request);
  }));
});
