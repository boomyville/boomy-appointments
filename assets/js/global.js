// Global modular JS utilities for API calls and form handling
(function (window) {
  'use strict';

  const THEME_STORAGE_KEY = 'boomy_theme_mode';

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
            if (form.dataset.noReload !== 'true') {
              // default: reload
              window.location.reload();
            }
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

  // Attach a client-side time updater for elements with data-server-time-format.
  function formatServerTime(date, format) {
    const pad = (value) => String(value).padStart(2, '0');
    const day = pad(date.getDate());
    const month = pad(date.getMonth() + 1);
    const year = String(date.getFullYear());
    const hours = pad(date.getHours());
    const minutes = pad(date.getMinutes());

    switch (format) {
      case 'Y/m/d H:i':
        return year + '/' + month + '/' + day + ' ' + hours + ':' + minutes;
      case 'm/d/Y H:i':
        return month + '/' + day + '/' + year + ' ' + hours + ':' + minutes;
      case 'd/m/Y H:i':
      default:
        return day + '/' + month + '/' + year + ' ' + hours + ':' + minutes;
    }
  }

  function attachServerTime(root = document) {
    const els = Array.from(root.querySelectorAll('[data-server-time-format]'));
    if (!els.length) return;

    const update = () => {
      const now = new Date();
      els.forEach(el => {
        const format = el.getAttribute('data-server-time-format') || 'd/m/Y H:i';
        el.textContent = formatServerTime(now, format);
      });
    };

    update();
    setInterval(update, 30000);
  }

  document.addEventListener('DOMContentLoaded', () => attachServerTime());

  function getPreferredTheme() {
    try {
      const saved = localStorage.getItem(THEME_STORAGE_KEY);
      if (saved === 'dark' || saved === 'light') return saved;
    } catch (err) {
      // Ignore storage failures and fall back to system/default theme.
    }
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function applyTheme(mode) {
    const theme = mode === 'dark' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.style.colorScheme = theme;
    document.body.classList.toggle('theme-dark', theme === 'dark');
    try {
      localStorage.setItem(THEME_STORAGE_KEY, theme);
    } catch (err) {
      // Ignore storage failures; the toggle still works for this session.
    }
    const btn = document.getElementById('themeToggleBtn');
    if (btn) {
      const next = theme === 'dark' ? 'Light' : 'Dark';
      btn.textContent = next + ' mode';
      btn.setAttribute('aria-label', 'Switch to ' + next.toLowerCase() + ' mode');
      btn.setAttribute('title', 'Switch to ' + next.toLowerCase() + ' mode');
    }

    const checkbox = document.getElementById('darkModeToggle');
    if (checkbox) {
      checkbox.checked = theme === 'dark';
    }
  }

  function attachThemeToggle() {
    if (!document.body || !document.head) {
      document.addEventListener('DOMContentLoaded', attachThemeToggle, { once: true });
      return;
    }

    const initial = getPreferredTheme();
    applyTheme(initial);

    if (document.getElementById('darkModeToggle')) {
      return;
    }

    if (document.getElementById('themeToggleBtn')) return;

    const button = document.createElement('button');
    button.id = 'themeToggleBtn';
    button.className = 'theme-toggle-button';
    button.type = 'button';
    button.addEventListener('click', () => {
      const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
      applyTheme(current === 'dark' ? 'light' : 'dark');
    });

    document.body.appendChild(button);
    applyTheme(initial);
  }

  function attachDarkModeCheckbox() {
    const checkbox = document.getElementById('darkModeToggle');
    if (!checkbox) return;

    checkbox.checked = document.documentElement.getAttribute('data-theme') === 'dark';
    if (checkbox.__themeToggleAttached) return;
    checkbox.__themeToggleAttached = true;

    const syncTheme = () => applyTheme(checkbox.checked ? 'dark' : 'light');
    checkbox.addEventListener('change', syncTheme);
    checkbox.addEventListener('input', syncTheme);
    checkbox.addEventListener('click', syncTheme);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      attachThemeToggle();
      attachDarkModeCheckbox();
    });
  } else {
    // DOM already ready (script may be injected late) — attach immediately
    attachThemeToggle();
    attachDarkModeCheckbox();
  }

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
    ,applyTheme
    ,attachThemeToggle
    ,attachDarkModeCheckbox
  });

})(window);
