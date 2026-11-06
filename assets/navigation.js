// Named entry points share the renderer, while each page has a real, reloadable URL.
(function () {
  // Freeze the installation root: relative <base> URLs otherwise change after pushState.
  const rootUrl = new URL(document.baseURI);
  const baseElement = document.querySelector('base');
  if (baseElement) baseElement.href = rootUrl.href;
  const publicPages = new Set(['about','products','community','contact','opportunity','cart','login','register','forgot']);
  const adminPages = new Set(['overview','products','pages','slides','settings','marketing','socials','members','orders','purchases','refunds','rules','payouts','audit','staff','security']);
  function routeFromLocation() {
    if (location.hash.length > 1) return location.hash.slice(1); // old bookmarks and email links
    const base = rootUrl.pathname;
    const path = location.pathname.startsWith(base) ? location.pathname.slice(base.length) : '';
    const parts = path.split('/');
    const file = (parts[1] || 'index.php').replace(/\.php$/, '');
    const params = new URLSearchParams(location.search);
    if (parts[0] === 'admin') return adminPages.has(file) ? 'admin/' + file : 'admin';
    if (parts[0] !== 'website') return 'home';
    if (file === 'index' || file === 'home') return 'home';
    if (file === 'product') return 'product/' + (params.get('id') || '');
    if (file === 'reset' || file === 'verify-email') return file + '/' + (params.get('token') || '');
    if (file === 'page') return params.get('slug') || 'home';
    return publicPages.has(file) ? file : 'home';
  }
  function urlForRoute(route) {
    const [name, id=''] = route.split('/');
    let path;
    if (name === 'admin') path = 'admin/' + (adminPages.has(id) ? id : 'index') + '.php';
    else if (name === 'home' || !name) path = 'website/index.php';
    else if (name === 'product') path = 'website/product.php?id=' + encodeURIComponent(id);
    else if (name === 'reset' || name === 'verify-email') path = 'website/' + name + '.php?token=' + encodeURIComponent(id);
    else if (publicPages.has(name)) path = 'website/' + name + '.php';
    else path = 'website/page.php?slug=' + encodeURIComponent(name);
    return new URL(path, rootUrl).href;
  }
  function navigate(route) {
    history.pushState(null, '', urlForRoute(route));
    window.dispatchEvent(new Event('app:navigate'));
  }
  function updateRouteLinks() {
    document.querySelectorAll('a[href^="#"]').forEach(link => {
      const route = link.getAttribute('href').slice(1);
      if (!route) return;
      link.dataset.appRoute = route;
      link.href = urlForRoute(route);
    });
  }
  document.addEventListener('click', event => {
    const link = event.target.closest('a[data-app-route]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target || link.hasAttribute('download')) return;
    event.preventDefault();
    navigate(link.dataset.appRoute);
  });
  window.currentRoute = routeFromLocation;
  window.navigate = navigate;
  window.updateRouteLinks = updateRouteLinks;
})();
