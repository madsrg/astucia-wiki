import { api } from '../core/api.js';
import { showToast } from '../core/utils.js';
import { t } from '../i18n/index.js';

const FONTS      = ['sans', 'serif', 'mono'];
const FONT_SIZES = ['10pt', '11pt', '12pt', '14pt', '16pt'];

const applyFont     = (f) => document.documentElement.setAttribute('data-font', f);
const applyFontSize = (s) => document.documentElement.setAttribute('data-font-size', s);

export const init = () => {
    const btn      = document.getElementById('preferences-btn');
    const lightbox = document.getElementById('preferences-lightbox');
    if (!btn || !lightbox) return;

    const closeBtn   = document.getElementById('preferences-lightbox-close-btn');
    const saveBtn    = document.getElementById('preferences-save-btn');
    const emailInput = document.getElementById('pref-email');
    const digestCb   = document.getElementById('pref-daily-digest');
    const fontBtns   = lightbox.querySelectorAll('[data-font-val]');
    const sizeBtns   = lightbox.querySelectorAll('[data-size-val]');

    let selectedFont     = window.WIKI_USER_FONT      || 'sans';
    let selectedFontSize = window.WIKI_USER_FONT_SIZE || '11pt';

    const updateFontBtns = (f) => fontBtns.forEach(b => {
        b.className = `btn btn-sm ${b.dataset.fontVal === f ? 'btn-blue' : 'btn-secondary'}`;
    });
    const updateSizeBtns = (s) => sizeBtns.forEach(b => {
        b.className = `btn btn-sm pref-size-btn ${b.dataset.sizeVal === s ? 'btn-blue' : 'btn-secondary'}`;
    });

    fontBtns.forEach(b => b.addEventListener('click', () => {
        selectedFont = b.dataset.fontVal;
        updateFontBtns(selectedFont);
        applyFont(selectedFont);
    }));

    sizeBtns.forEach(b => b.addEventListener('click', () => {
        selectedFontSize = b.dataset.sizeVal;
        updateSizeBtns(selectedFontSize);
        applyFontSize(selectedFontSize);
    }));

    const open = async () => {
        lightbox.classList.remove('hidden');
        emailInput.value = '';
        saveBtn.disabled = true;
        selectedFont     = window.WIKI_USER_FONT      || 'sans';
        selectedFontSize = window.WIKI_USER_FONT_SIZE || '11pt';
        updateFontBtns(selectedFont);
        updateSizeBtns(selectedFontSize);
        if (digestCb) digestCb.checked = false;
        const result = await api.call('user_get_preferences');
        if (result.success) {
            emailInput.value = result.data.email || '';
            if (FONTS.includes(result.data.fontFamily))      selectedFont     = result.data.fontFamily;
            if (FONT_SIZES.includes(result.data.fontSize))   selectedFontSize = result.data.fontSize;
            if (digestCb) digestCb.checked = !!result.data.dailyDigest;
            updateFontBtns(selectedFont);
            updateSizeBtns(selectedFontSize);
        }
        saveBtn.disabled = false;
        emailInput.focus();
    };

    const close = () => lightbox.classList.add('hidden');

    btn.addEventListener('click', open);
    closeBtn.addEventListener('click', close);
    lightbox.addEventListener('click', (e) => { if (e.target === e.currentTarget) close(); });

    saveBtn.addEventListener('click', async () => {
        saveBtn.disabled = true;
        const result = await api.call('user_save_preferences',
            { email: emailInput.value.trim(), fontFamily: selectedFont, fontSize: selectedFontSize,
              dailyDigest: digestCb && digestCb.checked ? '1' : '0' }, 'POST');
        saveBtn.disabled = false;
        if (result.success) {
            window.WIKI_USER_FONT      = selectedFont;
            window.WIKI_USER_FONT_SIZE = selectedFontSize;
            showToast(t('prefs.saved'), 'success');
            close();
        } else {
            showToast(result.message || t('prefs.failed'), 'error');
        }
    });

    emailInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter')  { e.preventDefault(); saveBtn.click(); }
        if (e.key === 'Escape') close();
    });
};
