(() => {
  'use strict';

  const input = document.getElementById('password-recovery-token');
  if (!(input instanceof HTMLInputElement)) return;

  const fragment = window.location.hash.startsWith('#')
    ? window.location.hash.slice(1)
    : '';
  const token = new URLSearchParams(fragment).get('token') || '';
  input.value = /^[A-Za-z0-9_-]{43}$/.test(token) ? token : '';

  if (window.location.hash) {
    history.replaceState(null, '', window.location.pathname + window.location.search);
  }
})();
