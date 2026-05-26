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
            // Dispatch success first so pages can show messages
            window.dispatchEvent(new CustomEvent('api:success', { detail: { form, json } }));
            // If the server provided a redirect, follow it unless the form opts out
            if (json.redirect && form.dataset.noReload !== 'true') {
              window.location.href = json.redirect;
              return;
            }
            // If form explicitly opted out of auto-reload/redirect, leave handling to page
            if (form.dataset.noReload !== 'true') {
              // default: reload when no redirect provided
              window.location.reload();
            }
          } else {
            window.dispatchEvent(new CustomEvent('api:error', { detail: { form, json } }));
            if (form.dataset.noReload !== 'true') {
              alert(json.error || json.message || 'Request failed');
            }
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

  // Attach live password requirement hints to a form
  // formOrElement: HTMLElement or form element
  // pwdSelector / confirmSelector: selector strings for password and confirm inputs
  // containerSelector: selector or element where hints will be rendered
  // requirements: object matching get_password_requirements() keys
  function attachPasswordHints(formOrElement, pwdSelector, confirmSelector, containerSelector, requirements) {
    const form = (typeof formOrElement === 'string') ? document.querySelector(formOrElement) : formOrElement;
    if (!form) return;
    const pwd = form.querySelector(pwdSelector) || document.querySelector(pwdSelector);
    const conf = form.querySelector(confirmSelector) || document.querySelector(confirmSelector);
    let container = (typeof containerSelector === 'string') ? document.querySelector(containerSelector) : containerSelector;
    if (!container) {
      container = document.createElement('div');
      if (form.firstElementChild) form.insertBefore(container, form.firstElementChild);
      else form.appendChild(container);
    }

    const req = Object.assign({
      min_length: 8,
      max_length: 128,
      require_lowercase: true,
      require_uppercase: true,
      require_number: true,
      require_symbol: true
    }, requirements || {});

    // build UI
    container.classList.add('pw-hints');
    container.innerHTML = '';
    const ul = document.createElement('ul');
    ul.style.listStyle = 'none';
    ul.style.padding = '0';
    ul.style.margin = '8px 0 0 0';

    const items = [];
    items.push({ key: 'min_length', text: `At least ${req.min_length} characters`, check: (v) => v.length >= req.min_length });
    items.push({ key: 'max_length', text: `No more than ${req.max_length} characters`, check: (v) => v.length <= req.max_length });
    if (req.require_lowercase) items.push({ key: 'lower', text: 'Contains a lowercase letter (a-z)', check: (v) => /[a-z]/.test(v) });
    if (req.require_uppercase) items.push({ key: 'upper', text: 'Contains an uppercase letter (A-Z)', check: (v) => /[A-Z]/.test(v) });
    if (req.require_number) items.push({ key: 'number', text: 'Contains a number (0-9)', check: (v) => /[0-9]/.test(v) });
    if (req.require_symbol) items.push({ key: 'symbol', text: 'Contains a symbol (e.g. !@#$%)', check: (v) => /[!@#$%^&*()_+\-=[\]{};:'",.<>?\/\\|`~]/.test(v) });
    items.push({ key: 'match', text: 'Passwords match', check: (v) => conf ? (v === (conf.value || '')) : true });

    items.forEach(it => {
      const li = document.createElement('li');
      li.style.margin = '4px 0';
      li.dataset.key = it.key;
      li.textContent = '\u2022 ' + it.text;
      li.style.color = '#b00020';
      ul.appendChild(li);
    });

    container.appendChild(ul);

    function update() {
      const v = pwd ? pwd.value || '' : '';
      items.forEach(it => {
        const li = ul.querySelector('li[data-key="' + it.key + '"]');
        if (!li) return;
        const ok = it.check(v);
        li.style.color = ok ? '#0b6623' : '#b00020';
        li.style.fontWeight = ok ? '600' : '400';
      });
    }

    if (pwd) pwd.addEventListener('input', update);
    if (conf) conf.addEventListener('input', update);
    // initial
    update();
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
    ,attachPasswordHints
  });

})(window);
