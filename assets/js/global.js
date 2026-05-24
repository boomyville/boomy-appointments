// Global modular JS utilities for API calls and form handling
(function (window) {
  'use strict';

  async function apiPost(url, data) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(data)
    });
    return res;
  }

  function formToObject(form) {
    const fd = new FormData(form);
    const obj = {};
    for (const [k, v] of fd.entries()) obj[k] = v;
    return obj;
  }

  function attachApiForms(root = document) {
    const forms = root.querySelectorAll('form[data-api]');
    forms.forEach(form => {
      if (form.__apiAttached) return;
      form.__apiAttached = true;
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const url = form.action;
        const payload = formToObject(form);
        try {
          const res = await apiPost(url, payload);
          const json = await res.json();
          if (res.ok) {
            window.dispatchEvent(new CustomEvent('api:success', { detail: { form, json } }));
            if (json.redirect) {
              window.location.href = json.redirect;
              return;
            }
            // default: reload
            window.location.reload();
          } else {
            window.dispatchEvent(new CustomEvent('api:error', { detail: { form, json } }));
            alert(json.error || json.message || 'Request failed');
          }
        } catch (err) {
          console.error(err);
          alert('Network or server error');
        }
      });
    });
  }

  document.addEventListener('DOMContentLoaded', () => attachApiForms());

  // Attach server time updater for elements with data-server-time-format
  async function updateServerTimeFor(el) {
    const format = el.getAttribute('data-server-time-format') || '';
    try {
      const url = '/time.php' + (format ? '?format=' + encodeURIComponent(format) : '');
      const res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) return;
      const json = await res.json();
      if (json && json.formatted) el.textContent = json.formatted;
    } catch (e) {
      // ignore
    }
  }

  function attachServerTime(root = document) {
    const els = Array.from(root.querySelectorAll('[data-server-time-format]'));
    if (!els.length) return;
    // initial update
    els.forEach(el => updateServerTimeFor(el));
    // refresh every 30 seconds
    setInterval(() => els.forEach(el => updateServerTimeFor(el)), 30000);
  }

  document.addEventListener('DOMContentLoaded', () => attachServerTime());

  // Email format validation (client-side only)
  function validateEmailFormat(email) {
    const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return regex.test(email);
  }

  function validateLoginForm(form) {
    const emailInput = form.querySelector('input[name="email"]');
    if (!emailInput) return true;
    const email = emailInput.value.trim();
    if (!email) return true;
    if (!validateEmailFormat(email)) {
      alert('Invalid email format. Expected: email@domain.com');
      emailInput.focus();
      return false;
    }
    return true;
  }

  // Intercept login/register forms for client-side email validation
  document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');

    if (loginForm) {
      loginForm.addEventListener('submit', (e) => {
        if (!validateLoginForm(loginForm)) {
          e.preventDefault();
          return false;
        }
      });
    }

    if (registerForm) {
      registerForm.addEventListener('submit', (e) => {
        if (!validateLoginForm(registerForm)) {
          e.preventDefault();
          return false;
        }
      });
    }
  });

  // Export minimal API
  window.App = Object.assign(window.App || {}, {
    apiPost,
    attachApiForms,
    formToObject
    ,attachServerTime
    ,validateEmailFormat
  });

})(window);
