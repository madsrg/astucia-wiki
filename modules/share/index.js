import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { showToast } from '../core/utils.js';
import { t } from '../i18n/index.js';

let _users = [];              // all human non-self users, fetched once
let _selectedUids = new Set(); // UIDs currently shown as chips
let _lastAutoMessage = '';    // last auto-generated message value; detect manual edits
let _focusedIdx = -1;

const _esc = (s) => (s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');

const _getPageLink = () => {
    if (!state.currentPageId) return window.location.href;
    const space = state.currentSpace ? '&space=' + encodeURIComponent(state.currentSpace) : '';
    return new URL('index.php?pageid=' + encodeURIComponent(state.currentPageId) + space, window.location.href).href;
};

const _buildGreeting = () => {
    const everyoneChecked = document.querySelector('input[name="share-to"][value="everyone"]')?.checked;
    if (everyoneChecked) return t('share.to-everyone');
    const names = [];
    document.querySelectorAll('.share-chip').forEach(c => { if (c.dataset.name) names.push(c.dataset.name); });
    return names.join(', ');
};

const _buildAutoMessage = () => {
    const greeting = _buildGreeting();
    const link     = _getPageLink();
    const sender   = document.body.dataset.userName || '';
    return `Hi ${greeting || 'everyone'},\n\nPlease checkout this page:\n\n${link}\n\n${sender}`;
};

const _refreshMessage = () => {
    const msgEl = document.getElementById('share-message');
    if (!msgEl) return;
    if (msgEl.value === _lastAutoMessage) {
        const newMsg = _buildAutoMessage();
        msgEl.value = newMsg;
        _lastAutoMessage = newMsg;
    }
};

// ── Suggestions ───────────────────────────────────────────────────────────────

const _hideSuggestions = () => {
    const el = document.getElementById('share-suggestions');
    if (el) { el.classList.add('hidden'); el.innerHTML = ''; }
    _focusedIdx = -1;
};

const _showSuggestions = (query) => {
    const sugEl = document.getElementById('share-suggestions');
    if (!sugEl) return;
    const q = query.toLowerCase().trim();
    const matches = _users
        .filter(u => !_selectedUids.has(u.uid) && u.name.toLowerCase().includes(q))
        .slice(0, 8);
    if (!matches.length) { _hideSuggestions(); return; }
    sugEl.innerHTML = matches.map(u =>
        `<li class="share-suggestion-item" data-uid="${u.uid}" data-name="${_esc(u.name)}">${_esc(u.name)}</li>`
    ).join('');
    sugEl.querySelectorAll('.share-suggestion-item').forEach(li => {
        li.addEventListener('mousedown', (e) => {
            e.preventDefault(); // keep input focused
            _addChip({ uid: parseInt(li.dataset.uid, 10), name: li.dataset.name });
        });
    });
    sugEl.classList.remove('hidden');
    _focusedIdx = -1;
};

const _moveFocus = (dir) => {
    const items = document.querySelectorAll('.share-suggestion-item');
    if (!items.length) return;
    items.forEach(i => i.classList.remove('focused'));
    _focusedIdx = Math.max(0, Math.min(items.length - 1, _focusedIdx + dir));
    items[_focusedIdx].classList.add('focused');
    items[_focusedIdx].scrollIntoView({ block: 'nearest' });
};

// ── Chips ─────────────────────────────────────────────────────────────────────

const _addChip = (user) => {
    if (_selectedUids.has(user.uid)) return;
    _selectedUids.add(user.uid);

    const inputEl = document.getElementById('share-typeahead');
    const chip = document.createElement('span');
    chip.className = 'share-chip';
    chip.dataset.uid  = user.uid;
    chip.dataset.name = user.name;
    chip.innerHTML = `${_esc(user.name)}<button type="button" class="share-chip-remove" aria-label="Remove">×</button>`;
    chip.querySelector('.share-chip-remove').addEventListener('click', () => {
        _selectedUids.delete(user.uid);
        chip.remove();
        _refreshMessage();
    });
    inputEl.parentElement.insertBefore(chip, inputEl);
    inputEl.value = '';
    _hideSuggestions();
    _refreshMessage();
    inputEl.focus();
};

// ── Typeahead wiring ──────────────────────────────────────────────────────────

const _initTypeahead = () => {
    const inputEl = document.getElementById('share-typeahead');
    if (!inputEl) return;

    inputEl.addEventListener('input', () => {
        const q = inputEl.value;
        if (!q) { _hideSuggestions(); return; }
        _showSuggestions(q);
    });

    inputEl.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown')  { e.preventDefault(); _moveFocus(1); return; }
        if (e.key === 'ArrowUp')    { e.preventDefault(); _moveFocus(-1); return; }
        if (e.key === 'Escape')     { _hideSuggestions(); return; }
        if (e.key === 'Enter') {
            e.preventDefault();
            const focused = document.querySelector('.share-suggestion-item.focused');
            if (focused) _addChip({ uid: parseInt(focused.dataset.uid, 10), name: focused.dataset.name });
            return;
        }
        if (e.key === 'Backspace' && inputEl.value === '') {
            const chips = document.querySelectorAll('.share-chip');
            if (chips.length) {
                const last = chips[chips.length - 1];
                _selectedUids.delete(parseInt(last.dataset.uid, 10));
                last.remove();
                _refreshMessage();
            }
        }
    });

    inputEl.addEventListener('blur', () => setTimeout(_hideSuggestions, 150));

    document.getElementById('share-chips-input')?.addEventListener('click', (e) => {
        if (e.target !== inputEl) inputEl.focus();
    });
};

// ── Lightbox open / close / send ──────────────────────────────────────────────

export const openShareLightbox = async () => {
    const lightbox      = document.getElementById('share-lightbox');
    const subjectEl     = document.getElementById('share-subject');
    const recipientWrap = document.getElementById('share-recipient-wrap');

    const pageName = (state.currentPagePath || '').split('/').pop().replace(/\.md$/, '');
    subjectEl.value = state.currentSpace ? `${state.currentSpace}: ${pageName}` : pageName;

    const everyoneRadio = document.querySelector('input[name="share-to"][value="everyone"]');
    if (everyoneRadio) everyoneRadio.checked = true;
    recipientWrap.classList.add('hidden');

    document.querySelectorAll('.share-chip').forEach(c => c.remove());
    _selectedUids.clear();
    const taEl = document.getElementById('share-typeahead');
    if (taEl) taEl.value = '';
    _hideSuggestions();

    if (_users.length === 0) {
        const res  = await api.call('get_user_list', {});
        _users = (res.data || []).filter(u => !u.is_ai && !u.is_system);
    }

    _lastAutoMessage = '';
    _refreshMessage();

    lightbox.classList.remove('hidden');
    subjectEl.focus();
};

const _close = () => {
    document.getElementById('share-lightbox')?.classList.add('hidden');
    _hideSuggestions();
};

const _send = async () => {
    const subject    = document.getElementById('share-subject').value.trim();
    const message    = document.getElementById('share-message').value.trim();
    const allChecked = document.querySelector('input[name="share-to"][value="everyone"]')?.checked;

    let to;
    if (allChecked) {
        to = 'everyone';
    } else {
        const uids = [..._selectedUids];
        if (uids.length === 0) {
            showToast(t('share.no-recipients'), 'error');
            return; // keep lightbox open
        }
        to = JSON.stringify(uids);
    }

    _close();

    const res = await api.call('share_page', {
        path:    state.currentPagePath || '',
        page_id: state.currentPageId   || '',
        subject,
        to,
        message,
    }, 'POST');

    if (res.success) {
        showToast(t('share.sent', { n: res.sent ?? 0 }), 'success');
        if (res.failed > 0) showToast(t('share.partial-fail', { n: res.failed }), 'error');
    }
};

export const openShare = openShareLightbox;

export const init = () => {
    if (!document.getElementById('share-lightbox')) return;

    _initTypeahead();

    document.getElementById('share-btn')?.addEventListener('click', openShareLightbox);
    document.getElementById('share-close-btn').addEventListener('click', _close);
    document.getElementById('share-cancel-btn').addEventListener('click', _close);
    document.getElementById('share-send-btn').addEventListener('click', _send);
    document.getElementById('share-lightbox').addEventListener('click', (e) => {
        if (e.target === document.getElementById('share-lightbox')) _close();
    });

    document.querySelectorAll('input[name="share-to"]').forEach(r => {
        r.addEventListener('change', () => {
            const specific = r.value === 'specific' && r.checked;
            document.getElementById('share-recipient-wrap').classList.toggle('hidden', !specific);
            _refreshMessage();
            if (specific) document.getElementById('share-typeahead')?.focus();
        });
    });

};
