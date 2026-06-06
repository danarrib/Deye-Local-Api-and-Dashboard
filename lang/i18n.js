const RTL_LANGUAGES = new Set(['he', 'ar']);

const SUPPORTED_LANGUAGES = [
  { code: 'en',    label: 'English' },
  { code: 'pt-BR', label: 'Português (Brasil)' },
  { code: 'es',    label: 'Español' },
  { code: 'de',    label: 'Deutsch' },
  { code: 'fr',    label: 'Français' },
  { code: 'it',    label: 'Italiano' },
  { code: 'nl',    label: 'Nederlands' },
  { code: 'tr',    label: 'Türkçe' },
  { code: 'ru',    label: 'Русский' },
  { code: 'zh-CN', label: '中文（简体）' },
  { code: 'zh-TW', label: '中文（繁體）' },
  { code: 'ko',    label: '한국어' },
  { code: 'ja',    label: '日本語' },
  { code: 'he',    label: 'עברית' },
  { code: 'ar',    label: 'العربية' },
  { code: 'hi',    label: 'हिन्दी' },
];

let _translations = {};
let _lang = 'en';
let _basePath = '';

function _browserLang() {
  const supported = SUPPORTED_LANGUAGES.map(l => l.code);
  for (const lang of (navigator.languages || [navigator.language])) {
    if (supported.includes(lang)) return lang;
    const match = supported.find(c => c.split('-')[0] === lang.split('-')[0]);
    if (match) return match;
  }
  return 'en';
}

async function i18nInit(basePath = '') {
  _basePath = basePath;

  try {
    const r = await fetch(basePath + 'settings.php?action=language');
    const s = await r.json();
    _lang = s.language || _browserLang();
  } catch(e) { _lang = _browserLang(); }

  try {
    const r = await fetch(basePath + `lang/${_lang}.json`, { cache: 'no-store' });
    _translations = await r.json();
  } catch(e) { _translations = {}; }

  const isRTL = RTL_LANGUAGES.has(_lang);
  document.documentElement.lang = _lang;
  document.documentElement.dir  = isRTL ? 'rtl' : 'ltr';

  const bsLink = document.getElementById('bootstrap-css');
  if (bsLink) {
    bsLink.href = isRTL
      ? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css'
      : 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css';
    bsLink.removeAttribute('integrity');
  }

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

function translateCondition(condition) {
  if (!condition) return '';
  const key = 'weather_cond_' + condition.toLowerCase().replace(/ /g, '_');
  const translated = t(key);
  return translated !== key ? translated : condition;
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
