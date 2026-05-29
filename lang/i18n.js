const SUPPORTED_LANGUAGES = [
  { code: 'en',    label: 'English' },
  { code: 'pt-BR', label: 'Português (Brasil)' },
  { code: 'es',    label: 'Español' },
];

let _translations = {};
let _lang = 'en';
let _basePath = '';

async function i18nInit(basePath = '') {
  _basePath = basePath;

  try {
    const r = await fetch(basePath + 'settings.php?action=language');
    const s = await r.json();
    _lang = s.language || 'en';
  } catch(e) { _lang = 'en'; }

  try {
    const r = await fetch(basePath + `lang/${_lang}.json`, { cache: 'no-store' });
    _translations = await r.json();
  } catch(e) { _translations = {}; }

  applyTranslations();
  _renderLangSwitchers();
}

function t(key, vars) {
  let str = _translations[key] !== undefined ? _translations[key] : key;
  if (vars) {
    for (const [k, v] of Object.entries(vars)) {
      str = str.split(`{${k}}`).join(String(v));
    }
  }
  return str;
}

function applyTranslations() {
  document.querySelectorAll('[data-i18n]').forEach(el => {
    el.textContent = t(el.getAttribute('data-i18n'));
  });
  document.querySelectorAll('[data-i18n-html]').forEach(el => {
    el.innerHTML = t(el.getAttribute('data-i18n-html'));
  });
  document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
    el.placeholder = t(el.getAttribute('data-i18n-placeholder'));
  });
}

async function setLanguage(code) {
  try {
    await fetch(_basePath + 'settings.php', {
      method: 'POST',
      body: new URLSearchParams({ language: code })
    });
  } catch(e) {}
  location.reload();
}

function _renderLangSwitchers() {
  document.querySelectorAll('.lang-switcher').forEach(sel => {
    sel.innerHTML = SUPPORTED_LANGUAGES.map(l =>
      `<option value="${l.code}"${l.code === _lang ? ' selected' : ''}>${l.label}</option>`
    ).join('');
    sel.onchange = e => setLanguage(e.target.value);
  });
}
