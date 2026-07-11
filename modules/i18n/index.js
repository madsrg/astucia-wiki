import en from './locales/en.js';
import da from './locales/da.js';
import sv from './locales/sv.js';
import es from './locales/es.js';
import fr from './locales/fr.js';
import de from './locales/de.js';

const LOCALES = { en, da, sv, es, fr, de };

export const SUPPORTED_LANGUAGES = {
    en: { label: 'English',  flag: '🇬🇧' },
    da: { label: 'Dansk',    flag: '🇩🇰' },
    sv: { label: 'Svenska',  flag: '🇸🇪' },
    es: { label: 'Español',  flag: '🇪🇸' },
    fr: { label: 'Français', flag: '🇫🇷' },
    de: { label: 'Deutsch',  flag: '🇩🇪' },
};

let _lang = 'en';

// Translate a key. Function values are called with vars; strings support {placeholder} substitution.
export const t = (key, vars = {}) => {
    const str = LOCALES[_lang]?.[key] ?? LOCALES.en[key] ?? key;
    if (typeof str === 'function') return str(vars);
    if (!vars || !Object.keys(vars).length) return str;
    return str.replace(/\{(\w+)\}/g, (_, k) => (vars[k] !== undefined ? vars[k] : `{${k}}`));
};

export const getLanguage = () => _lang;

export const setLanguage = (lang) => {
    if (!LOCALES[lang]) return;
    _lang = lang;
    localStorage.setItem('wiki_lang', lang);
    applyTranslations();
    document.documentElement.lang = lang;
    window.dispatchEvent(new CustomEvent('wiki:languagechange', { detail: { lang } }));
    _updateSelector();
};

// Scan DOM for data-i18n* attributes and apply translations.
export const applyTranslations = () => {
    document.querySelectorAll('[data-i18n]').forEach(el => {
        el.textContent = t(el.dataset.i18n);
    });
    document.querySelectorAll('[data-i18n-html]').forEach(el => {
        el.innerHTML = t(el.dataset.i18nHtml);
    });
    document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
        el.placeholder = t(el.dataset.i18nPlaceholder);
    });
    document.querySelectorAll('[data-i18n-title]').forEach(el => {
        el.title = t(el.dataset.i18nTitle);
    });
};

const _updateSelector = () => {
    document.querySelectorAll('.lang-option').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.lang === _lang);
    });
};

// Language is chosen from the My Preferences dialog for logged-in users, or —
// for anonymous / no-auth visitors who have no preferences page — from a
// sidebar globe dropdown. Both use .lang-option buttons; only one exists at a
// time, so wiring the class covers both. The dropdown toggle is wired only
// when the sidebar selector is present.
export const initSelector = () => {
    const dropdown = document.getElementById('lang-dropdown');
    const langBtn  = document.getElementById('lang-btn');

    document.querySelectorAll('.lang-option').forEach(opt => {
        opt.addEventListener('click', () => {
            setLanguage(opt.dataset.lang);
            dropdown?.classList.add('hidden');
        });
    });

    if (langBtn && dropdown) {
        langBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            dropdown.classList.toggle('hidden');
        });
        document.addEventListener('click', () => dropdown.classList.add('hidden'));
        dropdown.addEventListener('click', (e) => e.stopPropagation());
    }

    _updateSelector();
};

export const init = () => {
    const saved = localStorage.getItem('wiki_lang');
    const browser = navigator.language?.split('-')[0];
    const preferred = (saved && LOCALES[saved]) ? saved
        : (browser && LOCALES[browser]) ? browser
        : 'en';
    _lang = preferred;
    document.documentElement.lang = _lang;
    applyTranslations();
    initSelector();
};
