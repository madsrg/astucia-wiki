// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { api } from '../core/api.js';
import { showToast, confirmModal } from '../core/utils.js';
import { t } from '../i18n/index.js';
import { avatarPickerHtml, wireAvatarPicker } from '../core/avatars.js';
import { invalidateUsers } from '../core/users.js';

const FONTS      = ['sans', 'serif', 'mono'];
const FONT_SIZES = ['10pt', '11pt', '12pt', '14pt', '16pt'];

// ── Sign-in methods ─────────────────────────────────────────────────────────────
// One account may sign in with SSO, an email code, or both. Adding one needs this session
// to complete it — an SSO round trip, or a code sent to the new address — which is what
// stops anyone from attaching a way in to an account that is not theirs.

const esc = (v) => String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

// The API answers these with an i18n key in `error`; `message` is the English fallback.
const accountError = (res) => (res.error ? t(res.error) : '') || res.message || t('prefs.failed');

const renderSignin = (signin, reload) => {
    const group = document.getElementById('pref-signin-group');
    const box   = document.getElementById('pref-signin');
    if (!group || !box || !signin) return;
    const both = signin.sso && !!signin.otpEmail;
    group.classList.toggle('hidden', !signin.linkable && !both);
    if (group.classList.contains('hidden')) return;

    const row = (key, label, detail, action) => `
        <div class="pref-signin-row" data-method="${key}">
            <span class="pref-signin-name">${esc(label)}</span>
            <span class="pref-signin-detail${detail ? '' : ' pref-signin-none'}">${esc(detail || t('prefs.signin-none'))}</span>
            ${action}
        </div>`;
    const removeBtn = (m) => both ? `<button type="button" class="btn btn-sm btn-secondary" data-signin-remove="${m}">${esc(t('prefs.signin-remove'))}</button>` : '';
    const addBtn    = (m) => signin.linkable ? `<button type="button" class="btn btn-sm btn-blue" data-signin-add="${m}">${esc(t('prefs.signin-add'))}</button>` : '';
    box.innerHTML =
        row('sso', t('prefs.signin-sso'), signin.sso ? (signin.ssoEmail || t('prefs.signin-on')) : '', signin.sso ? removeBtn('sso') : addBtn('sso')) +
        row('otp', t('prefs.signin-otp'), signin.otpEmail, signin.otpEmail ? removeBtn('otp') : addBtn('otp')) +
        `<div id="pref-signin-otp-form" class="pref-signin-form hidden">
            <div class="pref-signin-step" data-step="email">
                <input type="email" class="form-control" id="pref-signin-email" placeholder="${esc(t('prefs.email-ph'))}">
                <button type="button" class="btn btn-sm btn-blue" id="pref-signin-send">${esc(t('prefs.signin-send'))}</button>
            </div>
            <div class="pref-signin-step hidden" data-step="code">
                <input type="text" class="form-control pref-signin-code" id="pref-signin-code" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="000000">
                <button type="button" class="btn btn-sm btn-blue" id="pref-signin-confirm">${esc(t('prefs.signin-confirm'))}</button>
            </div>
            <p class="pref-hint pref-hint-flush" id="pref-signin-form-hint">${esc(t('prefs.signin-otp-hint'))}</p>
        </div>`;

    box.querySelector('[data-signin-add="sso"]')?.addEventListener('click', async (e) => {
        e.target.disabled = true;
        const res = await api.call('account_sso_link_start', {}, 'POST');
        if (res.success && res.url) { window.location.href = res.url; return; }
        e.target.disabled = false;
        showToast(accountError(res), 'error');
    });

    const form = box.querySelector('#pref-signin-otp-form');
    box.querySelector('[data-signin-add="otp"]')?.addEventListener('click', (e) => {
        e.target.classList.add('hidden');
        form.classList.remove('hidden');
        form.querySelector('#pref-signin-email').focus();
    });
    form.querySelector('#pref-signin-send').addEventListener('click', async (e) => {
        const email = form.querySelector('#pref-signin-email').value.trim();
        if (!email) return;
        e.target.disabled = true;
        const res = await api.call('account_otp_add_send', { email }, 'POST');
        e.target.disabled = false;
        if (!res.success) { showToast(accountError(res), 'error'); return; }
        form.querySelector('[data-step="code"]').classList.remove('hidden');
        form.querySelector('#pref-signin-form-hint').textContent = t('prefs.signin-code-sent', { email });
        form.querySelector('#pref-signin-code').focus();
    });
    form.querySelector('#pref-signin-confirm').addEventListener('click', async (e) => {
        const code = form.querySelector('#pref-signin-code').value.trim();
        if (!code) return;
        e.target.disabled = true;
        const res = await api.call('account_otp_add_verify', { code }, 'POST');
        e.target.disabled = false;
        if (!res.success) { showToast(accountError(res), 'error'); return; }
        showToast(t('account.notice.otp-added'), 'success');
        reload();
    });

    box.querySelectorAll('[data-signin-remove]').forEach(btn => btn.addEventListener('click', async () => {
        const method = btn.dataset.signinRemove;
        const label  = method === 'sso' ? t('prefs.signin-sso') : t('prefs.signin-otp');
        const ok = await confirmModal(t('prefs.signin-remove-confirm', { method: label }),
            { confirmLabel: t('prefs.signin-remove'), cancelLabel: t('btn.cancel'), dangerous: true });
        if (!ok) return;
        btn.disabled = true;
        const res = await api.call('account_unlink', { method }, 'POST');
        if (!res.success) { btn.disabled = false; showToast(accountError(res), 'error'); return; }
        showToast(t('account.notice.removed', { method: label }), 'success');
        reload();
    }));
};

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
    const jobNotifyCb = document.getElementById('pref-notify-agent-jobs');
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

    // Rebuilt on each open from the server's options, so it can never offer an id the save
    // refuses and always starts from what is stored rather than from the last unsaved pick.
    const avatarSlot = document.getElementById('pref-avatar-slot');
    const renderAvatarPicker = (data) => {
        if (!avatarSlot) return;
        const initial = `<span class="avatar-initial">${((data.name || '?').charAt(0).toUpperCase())
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')}</span>`;
        avatarSlot.innerHTML = avatarPickerHtml('pref', data.avatar || '', data.avatars || [],
            { fallback: initial, noneLabel: t('avatar.none-person') });
        wireAvatarPicker('pref', initial);
    };

    const open = async () => {
        lightbox.classList.remove('hidden');
        emailInput.value = '';
        saveBtn.disabled = true;
        selectedFont     = window.WIKI_USER_FONT      || 'sans';
        selectedFontSize = window.WIKI_USER_FONT_SIZE || '11pt';
        updateFontBtns(selectedFont);
        updateSizeBtns(selectedFontSize);
        if (digestCb) digestCb.checked = false;
        if (jobNotifyCb) jobNotifyCb.checked = false;
        const result = await api.call('user_get_preferences');
        if (result.success) {
            emailInput.value = result.data.email || '';
            if (FONTS.includes(result.data.fontFamily))      selectedFont     = result.data.fontFamily;
            if (FONT_SIZES.includes(result.data.fontSize))   selectedFontSize = result.data.fontSize;
            if (digestCb) digestCb.checked = !!result.data.dailyDigest;
            if (jobNotifyCb) jobNotifyCb.checked = !!result.data.notifyAgentJobs;
            updateFontBtns(selectedFont);
            updateSizeBtns(selectedFontSize);
            renderAvatarPicker(result.data);
            renderSignin(result.data.signin, reloadSignin);
        }
        saveBtn.disabled = false;
        emailInput.focus();
    };

    // After adding or removing a method only that section is redrawn, so unsaved edits
    // elsewhere in the dialog are left alone.
    const reloadSignin = async () => {
        const res = await api.call('user_get_preferences');
        if (res.success) renderSignin(res.data.signin, reloadSignin);
    };

    const close = () => lightbox.classList.add('hidden');

    btn.addEventListener('click', open);
    closeBtn.addEventListener('click', close);
    lightbox.addEventListener('click', (e) => { if (e.target === e.currentTarget) close(); });

    saveBtn.addEventListener('click', async () => {
        saveBtn.disabled = true;
        const result = await api.call('user_save_preferences',
            { email: emailInput.value.trim(), fontFamily: selectedFont, fontSize: selectedFontSize,
              dailyDigest: digestCb && digestCb.checked ? '1' : '0',
              notifyAgentJobs: jobNotifyCb && jobNotifyCb.checked ? '1' : '0',
              // Absent until the options have loaded — and absent means "keep" server-side.
              ...(document.getElementById('pref-avatar') ? { avatar: document.getElementById('pref-avatar').value } : {}) }, 'POST');
        saveBtn.disabled = false;
        if (result.success) {
            window.WIKI_USER_FONT      = selectedFont;
            window.WIKI_USER_FONT_SIZE = selectedFontSize;
            // Your own bubbles repaint at once; other browsers hear it from the users event.
            invalidateUsers();
            showToast(t('prefs.saved'), 'success');
            close();
        } else {
            showToast(result.message || t('prefs.failed'), 'error');
        }
    });

    // Back from the provider after "Add single sign-on": auth.php says how it went in the
    // query string. Shown once, then taken out of the address bar.
    const qs = new URLSearchParams(window.location.search);
    const notice = qs.get('account_notice'), error = qs.get('account_error');
    if (notice || error) {
        const key = notice || error;
        // Only keys of the account family: the parameter is in the URL, so anything else
        // is somebody else's text.
        if (/^account\.(notice|err)\.[a-z-]+$/.test(key)) showToast(t(key), notice ? 'success' : 'error');
        qs.delete('account_notice');
        qs.delete('account_error');
        const rest = qs.toString();
        history.replaceState(history.state, '', window.location.pathname + (rest ? '?' + rest : '') + window.location.hash);
    }

    emailInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter')  { e.preventDefault(); saveBtn.click(); }
        if (e.key === 'Escape') close();
    });
};
