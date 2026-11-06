// Set this before the page paints and keep it in sync with hash navigation.
(function () {
  function syncAdminRoute() {
    document.documentElement.classList.toggle('admin-route', /^#admin(?:\/|$)/.test(location.hash) || (!location.hash && /\/admin\/(?:[^/]*\.php)?$/.test(location.pathname)));
  }
  window.addEventListener('hashchange', syncAdminRoute);
  window.addEventListener('popstate', syncAdminRoute);
  window.addEventListener('app:navigate', syncAdminRoute);
  syncAdminRoute();
})();
