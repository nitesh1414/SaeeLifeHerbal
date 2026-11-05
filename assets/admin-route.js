// Set this before the page paints and keep it in sync with hash navigation.
(function () {
  function syncAdminRoute() {
    document.documentElement.classList.toggle('admin-route', /^#admin(?:\/|$)/.test(location.hash));
  }
  window.addEventListener('hashchange', syncAdminRoute);
  syncAdminRoute();
})();
