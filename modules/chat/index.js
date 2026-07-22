import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { showToast, confirmModal } from '../core/utils.js';
import { getUsers, getMentionableUsers } from '../core/users.js';
import { getMcpServers } from '../core/mcp_servers.js';
import { t } from '../i18n/index.js';
import { openAiModal, closeAiModal, checkAiModal, startStatusPoll } from '../core/ai_modal.js';
import { getFocusAi, setFocusAi, applyFocus, createFocusChip } from '../core/chat_focus.js';
import { openSaveMessageDialog } from '../chat_save/index.js';

const EMOJIS     = ['😀','😂','😍','🤔','😢','😮','😡','👍','👎','👋','🙏','❤️','🎉','🔥','✅','❌','⭐','💡','🚀','📝','🎯','👀','💬','🤝'];
const REACTIONS  = ['👍','👎','❤️','😂','😮','🎉','🔥'];
const CHAT_COMMANDS = [
    { name: 'newTopic',  description: t('chat.cmd.new-topic') },
    { name: 'me',        description: t('chat.cmd.me') },
    { name: 'topic',     description: t('chat.cmd.topic') },
    { name: 'purge',     description: t('chat.cmd.purge') },
    { name: 'summarize', description: t('chat.cmd.summarize') },
    { name: 'aiUsers',   description: t('chat.cmd.ai-users') },
];
const POLL_MS = 5000;

let pollTimer     = null;
let _chatPath     = null;
let _hasMore      = false;
let _minVisibleId = null;
let _lastMtime    = 0;
let _loadingMore  = false;
let _aiUids       = new Set();
let _focusChip    = null;

// ── Focus mode ──────────────────────────────────────────────────────────────

const updateFocus = () => { if (_focusChip) _focusChip.update(getFocusAi(_chatPath)); };

const setFocus = (name) => { setFocusAi(_chatPath, name); updateFocus(); };

// Clicking an AI's avatar or name focuses the chat on it.
const attachFocusClick = (el, msg) => {
    if (!_aiUids.has(msg.uid)) return;
    el.classList.add('chat-avatar-focusable');
    el.title = t('chat.focus.start', { name: msg.name });
    el.addEventListener('click', () => setFocus(msg.name));
};

// ── Helpers ───────────────────────────────────────────────────────────────────

const escHtml = (s) => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

const formatTime = (ts) => {
    const d = new Date(ts);
    const now = new Date();
    const isToday = d.toDateString() === now.toDateString();
    if (isToday) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    return d.toLocaleDateString([], { month: 'short', day: 'numeric' })
        + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

const avatarColor = (uid) => {
    const palette = ['#4a90d9','#7c3aed','#059669','#d97706','#dc2626','#0891b2','#9333ea','#b45309'];
    return palette[(uid ?? 0) % palette.length];
};

const renderText = (raw, isAi = false) => {
    if (isAi && typeof marked !== 'undefined') {
        const html = marked.parse(String(raw ?? ''));
        // Highlight #mentions, but skip HTML entities so numeric ones like &#39;
        // (marked's output for an apostrophe) aren't mangled by matching "#39".
        return html.replace(/(&#?\w+;)|#(\w+)/g, (_, ent, name) => ent || `<span class="chat-mention">#${name}</span>`);
    }
    return escHtml(raw).replace(/#(\S+)/g, '<span class="chat-mention">#$1</span>');
};

const isNearBottom = (el) => el.scrollHeight - el.scrollTop - el.clientHeight < 80;


// ── Reaction picker (shared singleton) ───────────────────────────────────────

let _reactionPicker = null;

const getReactionPicker = () => {
    if (_reactionPicker) return _reactionPicker;
    const picker = document.createElement('div');
    picker.className = 'chat-reaction-picker hidden';
    REACTIONS.forEach(e => {
        const btn = document.createElement('button');
        btn.className = 'chat-reaction-pick-btn';
        btn.textContent = e;
        btn.title = e;
        picker.appendChild(btn);
    });
    document.body.appendChild(picker);
    document.addEventListener('click', () => picker.classList.add('hidden'));
    _reactionPicker = picker;
    return picker;
};

const showReactionPicker = (msgId, anchorEl) => {
    const picker = getReactionPicker();
    const rect   = anchorEl.getBoundingClientRect();
    picker.style.top  = (rect.bottom + 4) + 'px';
    picker.style.left = Math.min(rect.left, window.innerWidth - 260) + 'px';
    picker.classList.remove('hidden');
    picker.querySelectorAll('.chat-reaction-pick-btn').forEach((btn, i) => {
        btn.onclick = (e) => {
            e.stopPropagation();
            picker.classList.add('hidden');
            toggleReaction(msgId, REACTIONS[i]);
        };
    });
};

const toggleReaction = async (msgId, emoji) => {
    const res = await api.call('toggle_reaction', { file: _chatPath, id: msgId, emoji }, 'POST');
    if (res.success) {
        const msgEl    = document.getElementById('chat-messages');
        const atBottom = msgEl ? isNearBottom(msgEl) : true;
        const savedTop = msgEl ? msgEl.scrollTop : 0;
        renderChatView(_applyFullDataToWindow(res.data), _hasMore, false);
        if (msgEl) msgEl.scrollTop = atBottom ? msgEl.scrollHeight : savedTop;
    } else showToast(res.message || 'Failed to react', 'error');
};

const buildReactionBar = (msg) => {
    const raw = msg.reactions;
    const reactions = (raw && !Array.isArray(raw)) ? raw : {};
    const currentUid = window.WIKI_USER_UID ?? -1;
    const bar = document.createElement('div');
    bar.className = 'chat-reaction-bar';
    for (const [emoji, uids] of Object.entries(reactions)) {
        if (!Array.isArray(uids) || !uids.length) continue;
        const isMine = uids.includes(currentUid);
        const pill = document.createElement('button');
        pill.className = 'chat-reaction-pill' + (isMine ? ' chat-reaction-pill-mine' : '');
        pill.textContent = `${emoji} ${uids.length}`;
        pill.title = `${uids.length} reaction${uids.length !== 1 ? 's' : ''}`;
        pill.addEventListener('click', () => toggleReaction(msg.id, emoji));
        bar.appendChild(pill);
    }
    return bar;
};

// The hover-only action buttons (reply, save, append, add-reaction; pin is added
// by the caller). Returned in their own container so it can be floated as an
// overlay — revealing it on hover then never changes the message layout (no jump,
// no permanently reserved space). See .chat-actions in styles.css.
const buildMsgActions = (msg) => {
    const actions = document.createElement('div');
    actions.className = 'chat-actions';

    const replyBtn = document.createElement('button');
    replyBtn.className = 'chat-reply-btn';
    replyBtn.title = t('chat.reply-title', { name: msg.name || 'this message' });
    replyBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>';
    replyBtn.addEventListener('click', () => {
        const textarea = document.getElementById('chat-input');
        if (!textarea) return;
        const mention = '#' + (msg.name || '') + ' ';
        textarea.value = textarea.value ? textarea.value.trimEnd() + ' ' + mention : mention;
        textarea.selectionStart = textarea.selectionEnd = textarea.value.length;
        textarea.focus();
        autoResize(textarea);
    });
    actions.appendChild(replyBtn);

    const saveBtn = document.createElement('button');
    saveBtn.className = 'chat-save-btn';
    saveBtn.title = t('chat.save.action-save');
    saveBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>';
    saveBtn.addEventListener('click', () => openSaveMessageDialog(msg.text, 'create'));
    actions.appendChild(saveBtn);

    const appendBtn = document.createElement('button');
    appendBtn.className = 'chat-save-btn';
    appendBtn.title = t('chat.save.action-append');
    appendBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9.5 15.5 12 18 14.5 15.5"/></svg>';
    appendBtn.addEventListener('click', () => openSaveMessageDialog(msg.text, 'append'));
    actions.appendChild(appendBtn);

    const addBtn = document.createElement('button');
    addBtn.className = 'chat-reaction-add-btn';
    addBtn.textContent = '😊';
    addBtn.title = t('chat.reaction-title');
    addBtn.addEventListener('click', (e) => { e.stopPropagation(); showReactionPicker(msg.id, addBtn); });
    actions.appendChild(addBtn);
    return actions;
};

// ── Sticky area ───────────────────────────────────────────────────────────────

const buildStickyArea = (messages) => {
    const area = document.getElementById('chat-sticky-area');
    if (!area) return;
    const sticky = messages.filter(m => m.sticky);
    if (!sticky.length) { area.classList.add('hidden'); area.innerHTML = ''; return; }
    const currentUid  = window.WIKI_USER_UID ?? -1;
    const currentRole = window.WIKI_ROLE || '';
    area.classList.remove('hidden');
    area.innerHTML = '';
    sticky.forEach(msg => {
        const row = document.createElement('div');
        row.className = 'chat-sticky-row';
        const text = msg.text.length > 100 ? msg.text.slice(0, 100) + '…' : msg.text;
        row.innerHTML = `<span class="chat-sticky-icon">📌</span><span class="chat-sticky-author">${escHtml(msg.name)}</span><span class="chat-sticky-text">${escHtml(text)}</span>`;
        if (msg.uid === currentUid || currentRole === 'admin') {
            const close = document.createElement('button');
            close.className = 'chat-sticky-close';
            close.title = 'Unpin message';
            close.innerHTML = '&times;';
            close.addEventListener('click', async () => {
                const res = await api.call('toggle_sticky', { file: _chatPath, id: msg.id }, 'POST');
                if (res.success) renderChatView(_applyFullDataToWindow(res.data), _hasMore, false);
                else showToast(res.message || 'Failed to unpin', 'error');
            });
            row.appendChild(close);
        }
        area.appendChild(row);
    });
};

// ── Row builder (shared by full render and append) ────────────────────────────

const buildRow = (msg, grouped) => {
    if (msg.is_action) {
        const row = document.createElement('div');
        row.className = 'chat-action-row';
        row.dataset.id = msg.id;
        const span = document.createElement('span');
        span.className = 'chat-action-text';
        span.textContent = `* ${msg.name} ${msg.text} *`;
        row.appendChild(span);
        return row;
    }

    if (msg.is_new_topic) {
        const strippedText = msg.text.replace(/^\/newTopic\s*/i, '').trim();
        const divider = document.createElement('div');
        divider.className = 'chat-new-topic-divider';
        divider.dataset.id = msg.id;
        divider.innerHTML = `<span class="chat-new-topic-label">${t('chat.new-topic')}</span>`;
        if (!strippedText) return divider;
        const frag = document.createDocumentFragment();
        frag.appendChild(divider);
        frag.appendChild(buildRow({ ...msg, is_new_topic: false, text: strippedText }, false));
        return frag;
    }

    const currentUid  = window.WIKI_USER_UID ?? -1;
    const currentRole = window.WIKI_ROLE || '';
    const isMe     = msg.uid === currentUid;
    const isSticky = msg.sticky === true;

    const row = document.createElement('div');
    row.className = 'chat-row' + (isMe ? ' chat-row-mine' : '');
    row.dataset.id = msg.id;

    const avatarEl = document.createElement('div');
    if (!grouped) {
        avatarEl.className = 'chat-avatar';
        avatarEl.textContent = (msg.name || '?').charAt(0).toUpperCase();
        avatarEl.style.background = avatarColor(msg.uid);
        attachFocusClick(avatarEl, msg);
    } else {
        avatarEl.className = 'chat-avatar-gap';
    }
    row.appendChild(avatarEl);

    const col = document.createElement('div');
    col.className = 'chat-col';

    if (!grouped) {
        const meta = document.createElement('div');
        meta.className = 'chat-meta';
        meta.innerHTML = `<span class="chat-name">${escHtml(msg.name)}</span><span class="chat-time">${formatTime(msg.timestamp)}</span>`;
        attachFocusClick(meta.querySelector('.chat-name'), msg);
        col.appendChild(meta);
    }

    const bubble = document.createElement('div');
    const isAiMsg = _aiUids.has(msg.uid);
    let bubbleClass = 'chat-bubble' + (isMe ? ' chat-bubble-mine' : '') + (isAiMsg ? ' chat-bubble-md' : '');
    if (isSticky) bubbleClass += ' chat-bubble-sticky';
    bubble.className = bubbleClass;

    if (msg.pending) {
        const age = Date.now() - new Date(msg.timestamp).getTime();
        const TIMEOUT_MS = 300_000;
        if (age >= TIMEOUT_MS) {
            bubble.innerHTML = `<span class="chat-pending-timeout">${t('chat.timeout')}</span>`;
        } else {
            bubble.innerHTML = `<span class="chat-pending-indicator"><span class="chat-spinner"></span>${t('chat.working')}</span>`;
            // Flip to timeout state when the deadline passes, but only if still on this chat
            const capturedPath = _chatPath;
            setTimeout(() => {
                if (_chatPath === capturedPath && state.currentChatData) {
                    renderChatView(state.currentChatData);
                }
            }, TIMEOUT_MS - age + 500);
        }
        col.appendChild(bubble);
        row.appendChild(col);
        return row;
    }

    bubble.innerHTML = renderText(msg.text, _aiUids.has(msg.uid));

    if (isMe || currentRole === 'admin') {
        const del = document.createElement('button');
        del.className = 'chat-del-btn';
        del.title = t('chat.delete-title');
        del.innerHTML = '&times;';
        del.addEventListener('click', async () => {
            const ok = await confirmModal(t('chat.delete-confirm'), { confirmLabel: t('btn.delete'), dangerous: true });
            if (!ok) return;
            const res = await api.call('delete_chat_message', { file: _chatPath, id: msg.id }, 'POST');
            if (res.success) {
                const msgEl    = document.getElementById('chat-messages');
                const atBottom = msgEl ? isNearBottom(msgEl) : true;
                const savedTop = msgEl ? msgEl.scrollTop : 0;
                renderChatView(_applyFullDataToWindow(res.data), _hasMore, false);
                if (msgEl) msgEl.scrollTop = atBottom ? msgEl.scrollHeight : savedTop;
            } else showToast(res.message || 'Failed to delete', 'error');
        });
        bubble.appendChild(del);
    }

    // Hover action toolbar — floated as an overlay (see .chat-actions) so it never
    // shifts the message layout when revealed. Lives inside the bubble so it is
    // positioned relative to it, but only shows on .chat-col hover.
    const actions = buildMsgActions(msg);
    if (isMe || currentRole === 'admin') {
        const pin = document.createElement('button');
        pin.className = 'chat-pin-btn' + (isSticky ? ' chat-pin-btn-active' : '');
        pin.title = isSticky ? t('chat.unpin-title') : t('chat.pin-title');
        pin.textContent = '📌';
        pin.addEventListener('click', async () => {
            const res = await api.call('toggle_sticky', { file: _chatPath, id: msg.id }, 'POST');
            if (res.success) renderChatView(_applyFullDataToWindow(res.data), _hasMore, false);
            else showToast(res.message || 'Failed to pin', 'error');
        });
        actions.appendChild(pin);
    }
    bubble.appendChild(actions);

    col.appendChild(bubble);
    col.appendChild(buildReactionBar(msg));
    row.appendChild(col);
    return row;
};

// ── Topic bar ─────────────────────────────────────────────────────────────────

const updateTopicBar = (topic) => {
    const bar  = document.getElementById('chat-topic-bar');
    const text = document.getElementById('chat-topic-text');
    if (!bar || !text) return;
    if (topic) {
        text.textContent = topic;
        bar.classList.remove('hidden');
    } else {
        bar.classList.add('hidden');
    }
};

// ── Pagination helpers ────────────────────────────────────────────────────────

const _applyFullDataToWindow = (fullData) => {
    const allMsgs = fullData.messages || [];
    const allById = new Map(allMsgs.map(m => [m.id, m]));
    const curMsgs = state.currentChatData?.messages || [];
    const maxId   = curMsgs.length ? curMsgs[curMsgs.length - 1].id : 0;
    const updated = curMsgs.filter(m => allById.has(m.id)).map(m => allById.get(m.id));
    const added   = allMsgs.filter(m => m.id > maxId);
    state.currentChatData = { ...fullData, messages: [...updated, ...added] };
    return state.currentChatData;
};

const _buildLoadMoreBtn = () => {
    const btn = document.createElement('button');
    btn.id = 'chat-load-more-btn';
    btn.className = 'btn btn-sm btn-secondary chat-load-more-btn';
    btn.textContent = t('chat.load-older');
    btn.addEventListener('click', _loadOlderMessages);
    return btn;
};

const _loadOlderMessages = async () => {
    if (_loadingMore || _minVisibleId === null) return;
    _loadingMore = true;

    const btn = document.getElementById('chat-load-more-btn');
    if (btn) { btn.disabled = true; btn.textContent = t('chat.loading-older'); }

    const container = document.getElementById('chat-messages');
    const savedScrollHeight = container.scrollHeight;

    const res = await api.call('chat_messages', { file: _chatPath, before_id: _minVisibleId });
    _loadingMore = false;

    if (!res.success) {
        showToast(res.message || t('chat.load-older-failed'), 'error');
        if (btn) { btn.disabled = false; btn.textContent = t('chat.load-older'); }
        return;
    }

    if (btn) btn.remove();

    const msgs = res.messages || [];
    if (!msgs.length) { _hasMore = false; return; }

    _minVisibleId = msgs[0].id;
    _hasMore = res.has_more;

    const insertRef = container.firstChild;
    if (res.has_more) container.insertBefore(_buildLoadMoreBtn(), insertRef);

    let prevUid = null;
    msgs.forEach((msg, i) => {
        container.insertBefore(buildRow(msg, i > 0 && msg.uid === prevUid), insertRef);
        prevUid = msg.uid;
    });

    container.scrollTop = container.scrollHeight - savedScrollHeight;

    const existingIds = new Set((state.currentChatData?.messages || []).map(m => m.id));
    state.currentChatData = {
        ...state.currentChatData,
        messages: [...msgs.filter(m => !existingIds.has(m.id)), ...(state.currentChatData?.messages || [])],
    };
};

// ── Render (full) ─────────────────────────────────────────────────────────────

export const renderChatView = (data, hasMore = false, scrollToBottom = true) => {
    const container = document.getElementById('chat-messages');
    if (!container) return;

    _hasMore = hasMore;
    updateTopicBar(data.topic || '');
    const messages = data.messages || [];
    buildStickyArea(messages);

    _minVisibleId = messages.length ? messages[0].id : null;
    container.innerHTML = '';

    if (hasMore) container.appendChild(_buildLoadMoreBtn());

    if (!messages.length && !hasMore) {
        container.innerHTML = `<p class="chat-empty">${t('chat.empty')}</p>`;
        return;
    }

    let prevUid = null;
    messages.forEach(msg => {
        container.appendChild(buildRow(msg, msg.uid === prevUid));
        prevUid = msg.uid;
    });
    if (scrollToBottom) container.scrollTop = container.scrollHeight;
};

// ── Append new messages only ──────────────────────────────────────────────────

const appendNewMessages = (newMsgs, prevUid) => {
    const container = document.getElementById('chat-messages');
    if (!container) return;

    const placeholder = container.querySelector('.chat-empty');
    if (placeholder) placeholder.remove();

    const shouldScroll = isNearBottom(container);

    newMsgs.forEach(msg => {
        container.appendChild(buildRow(msg, msg.uid === prevUid));
        prevUid = msg.uid;
    });

    if (shouldScroll) container.scrollTop = container.scrollHeight;
    checkAiModal(state.currentChatData?.messages || []);
};

// ── Polling ───────────────────────────────────────────────────────────────────

export const stopPolling = () => {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    _chatPath = null;
    updateFocus();
    closeAiModal();
};

export const startPolling = (path, initialMtime = 0) => {
    _lastMtime = initialMtime;
    stopPolling();
    _chatPath = path;
    updateFocus();
    pollTimer = setInterval(async () => {
        // Stop if a different non-folder page is opened; folder clicks don't unload the chat
        if (state.currentPageType !== 'folder' && state.currentPagePath !== path) { stopPolling(); return; }

        const current = state.currentChatData?.messages || [];
        const maxId   = current.length ? current[current.length - 1].id : 0;

        const res = await api.call('chat_messages', { file: path, since_id: maxId });
        if (!res.success) return;

        const newMsgs = res.messages || [];
        const mtime   = res.mtime || 0;

        if (newMsgs.length) {
            _lastMtime = mtime;
            const prevUid = current.length ? current[current.length - 1].uid : null;
            state.currentChatData = { ...state.currentChatData, messages: [...current, ...newMsgs] };
            appendNewMessages(newMsgs, prevUid);
            return;
        }

        if (mtime > _lastMtime) {
            _lastMtime = mtime;
            const full = await api.call('get', { file: path });
            if (!full.success) return;
            let fullData;
            try { fullData = JSON.parse(full.data); } catch { return; }
            const msgEl    = document.getElementById('chat-messages');
            const atBottom = msgEl ? isNearBottom(msgEl) : true;
            const savedTop = msgEl ? msgEl.scrollTop : 0;
            renderChatView(_applyFullDataToWindow(fullData), _hasMore, false);
            if (msgEl) msgEl.scrollTop = atBottom ? msgEl.scrollHeight : savedTop;
            checkAiModal(state.currentChatData?.messages || []);
        }
    }, POLL_MS);
};

// ── Mention + command autocomplete ────────────────────────────────────────────

const setupMentionAutocomplete = (textarea, popup) => {
    let triggerStart = -1;
    let triggerChar  = '';
    let selectedIdx  = -1;

    const getItems = () => Array.from(popup.querySelectorAll('.chat-mention-item'));

    const setSelected = (idx) => {
        const items = getItems();
        items.forEach((el, i) => el.classList.toggle('chat-mention-item-active', i === idx));
        selectedIdx = idx;
        if (idx >= 0 && items[idx]) items[idx].scrollIntoView({ block: 'nearest' });
    };

    const insertSelected = () => {
        const active = selectedIdx >= 0 ? getItems()[selectedIdx] : getItems()[0];
        if (!active) return false;
        active.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        return true;
    };

    const closePop = () => {
        popup.classList.add('hidden');
        popup.classList.remove('chat-mention-popup-cmd');
        selectedIdx  = -1;
        triggerStart = -1;
        triggerChar  = '';
    };

    textarea.addEventListener('input', async () => {
        const val = textarea.value;
        const pos = textarea.selectionStart;

        // "src:<slug>" is a word-prefix trigger (unlike the single-char # / triggers
        // below) — explicitly forces an AI reply to use only that MCP server's tools.
        let wordStart = pos - 1;
        while (wordStart >= 0 && val[wordStart] !== ' ' && val[wordStart] !== '\n') wordStart--;
        wordStart++;
        const word = val.slice(wordStart, pos);
        if (/^src:/i.test(word)) {
            triggerStart = wordStart;
            triggerChar  = 'src:';
            const query  = word.slice(4).toLowerCase();
            selectedIdx  = -1;
            popup.innerHTML = '';
            popup.classList.add('chat-mention-popup-cmd');
            const servers = (await getMcpServers())
                .filter(s => s.slug.startsWith(query) || s.name.toLowerCase().startsWith(query))
                .slice(0, 6);
            if (!servers.length) { closePop(); return; }
            servers.forEach(s => {
                const item = document.createElement('div');
                item.className = 'chat-mention-item';
                const nameEl = document.createElement('span');
                nameEl.className = 'chat-cmd-name';
                nameEl.textContent = 'src:' + s.slug;
                const descEl = document.createElement('span');
                descEl.className = 'chat-cmd-desc';
                descEl.textContent = s.name;
                item.append(nameEl, descEl);
                item.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    const curPos = textarea.selectionStart;
                    const insert = 'src:' + s.slug + ' ';
                    textarea.value = textarea.value.slice(0, triggerStart) + insert + textarea.value.slice(curPos);
                    textarea.selectionStart = textarea.selectionEnd = triggerStart + insert.length;
                    closePop();
                });
                popup.appendChild(item);
            });
            popup.classList.remove('hidden');
            return;
        }

        let start = pos - 1;
        while (start >= 0 && val[start] !== '#' && val[start] !== '/' && val[start] !== ' ' && val[start] !== '\n') start--;
        if (start < 0 || (val[start] !== '#' && val[start] !== '/')) { closePop(); return; }
        // slash commands must be at the very start of the message
        if (val[start] === '/' && start !== 0) { closePop(); return; }

        triggerStart = start;
        triggerChar  = val[start];
        const query  = val.slice(start + 1, pos).toLowerCase();
        selectedIdx  = -1;
        popup.innerHTML = '';

        if (triggerChar === '#') {
            popup.classList.remove('chat-mention-popup-cmd');
            const matches = (await getMentionableUsers()).filter(u => u.name.toLowerCase().startsWith(query)).slice(0, 6);
            if (!matches.length) { closePop(); return; }
            matches.forEach(u => {
                const item = document.createElement('div');
                item.className = 'chat-mention-item';
                item.textContent = '#' + u.name;
                item.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    const curPos = textarea.selectionStart;
                    const insert = '#' + u.name + ' ';
                    textarea.value = textarea.value.slice(0, triggerStart) + insert + textarea.value.slice(curPos);
                    textarea.selectionStart = textarea.selectionEnd = triggerStart + insert.length;
                    closePop();
                });
                popup.appendChild(item);
            });
        } else {
            popup.classList.add('chat-mention-popup-cmd');
            const matches = CHAT_COMMANDS.filter(c => c.name.toLowerCase().startsWith(query));
            if (!matches.length) { closePop(); return; }
            matches.forEach(c => {
                const item = document.createElement('div');
                item.className = 'chat-mention-item';
                const nameEl = document.createElement('span');
                nameEl.className = 'chat-cmd-name';
                nameEl.textContent = '/' + c.name;
                const descEl = document.createElement('span');
                descEl.className = 'chat-cmd-desc';
                descEl.textContent = c.description;
                item.append(nameEl, descEl);
                item.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    const curPos = textarea.selectionStart;
                    const insert = '/' + c.name + ' ';
                    textarea.value = textarea.value.slice(0, triggerStart) + insert + textarea.value.slice(curPos);
                    textarea.selectionStart = textarea.selectionEnd = triggerStart + insert.length;
                    closePop();
                });
                popup.appendChild(item);
            });
        }
        popup.classList.remove('hidden');
    });

    textarea.addEventListener('blur', () => setTimeout(closePop, 150));

    textarea.addEventListener('keydown', (e) => {
        if (popup.classList.contains('hidden')) return;
        const items = getItems();
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setSelected(Math.min(selectedIdx + 1, items.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setSelected(Math.max(selectedIdx - 1, 0));
        } else if (e.key === 'Tab' || e.key === 'Enter') {
            e.preventDefault();
            e.stopImmediatePropagation();
            insertSelected();
        } else if (e.key === 'Escape') {
            closePop();
        }
    });
};

// ── Auto-resize textarea ──────────────────────────────────────────────────────

const autoResize = (el) => {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
};

// ── Init (runs once at page load) ─────────────────────────────────────────────

export const init = () => {
    const sendBtn     = document.getElementById('chat-send-btn');
    const textarea    = document.getElementById('chat-input');
    const emojiBtn    = document.getElementById('chat-emoji-btn');
    const emojiPicker = document.getElementById('chat-emoji-picker');
    const mentionPop  = document.getElementById('chat-mention-popup');
    const newTopicChk = document.getElementById('chat-newtopic-chk');
    if (!sendBtn) return;

    EMOJIS.forEach(e => {
        const btn = document.createElement('button');
        btn.className = 'chat-emoji-item';
        btn.textContent = e;
        btn.addEventListener('click', () => {
            const p = textarea.selectionStart;
            textarea.value = textarea.value.slice(0, p) + e + textarea.value.slice(p);
            textarea.selectionStart = textarea.selectionEnd = p + e.length;
            textarea.focus();
            emojiPicker.classList.add('hidden');
            autoResize(textarea);
        });
        emojiPicker.appendChild(btn);
    });

    emojiBtn.addEventListener('click', (ev) => { ev.stopPropagation(); emojiPicker.classList.toggle('hidden'); });
    document.addEventListener('click', (ev) => {
        if (!emojiPicker.contains(ev.target) && ev.target !== emojiBtn) emojiPicker.classList.add('hidden');
    });

    setupMentionAutocomplete(textarea, mentionPop);
    textarea.addEventListener('input', () => autoResize(textarea));

    const inputArea = textarea.closest('.chat-input-area');
    if (inputArea) _focusChip = createFocusChip(inputArea, textarea, { onExit: () => setFocus(null) });

    const sendMessage = async () => {
        let text = textarea.value.trim();
        if (!text) return;

        // "New Topic" checkbox: reset the AI's context for this message, exactly as
        // typing /newTopic does. Consume (uncheck) it on any real submit; only
        // prepend for normal messages — a slash command would be swallowed by the
        // prefix, and the user isn't starting a topic when running one.
        const wantNewTopic = newTopicChk?.checked;
        if (newTopicChk) newTopicChk.checked = false;
        if (wantNewTopic && !text.startsWith('/')) text = '/newTopic ' + text;

        if (text.startsWith('/')) {
            const spaceIdx = text.indexOf(' ');
            const cmd = (spaceIdx === -1 ? text : text.slice(0, spaceIdx)).toLowerCase();
            const arg = spaceIdx === -1 ? '' : text.slice(spaceIdx + 1).trim();
            if (cmd === '/topic') {
                if (!arg) { showToast(t('chat.cmd.topic-usage'), 'error'); return; }
                textarea.value = ''; autoResize(textarea);
                const res = await api.call('update_chat_topic', { file: _chatPath, topic: arg }, 'POST');
                if (res.success) renderChatView(_applyFullDataToWindow(res.data), _hasMore, false);
                else showToast(res.message || t('chat.cmd.topic-fail'), 'error');
                return;
            }
            if (cmd === '/purge') {
                const noConfirm = /\b-y\b/i.test(arg);
                const keep = parseInt(arg.replace(/-y/gi, '').trim(), 10);
                if (isNaN(keep) || keep < 0) { showToast(t('chat.cmd.purge-usage'), 'error'); return; }
                if (!noConfirm) {
                    const confirmMsg = keep === 0 ? t('chat.cmd.purge-confirm-all') : t('chat.cmd.purge-confirm', { keep });
                    const ok = await confirmModal(confirmMsg, { confirmLabel: t('chat.cmd.purge-btn'), dangerous: true });
                    if (!ok) return;
                }
                textarea.value = ''; autoResize(textarea);
                const res = await api.call('purge_chat_messages', { file: _chatPath, keep }, 'POST');
                if (res.success) renderChatView(_applyFullDataToWindow(res.data), _hasMore, false);
                else showToast(res.message || t('chat.cmd.purge-fail'), 'error');
                return;
            }
            if (cmd === '/summarize') {
                textarea.value = '#';
                textarea.dispatchEvent(new Event('input'));
                textarea.selectionStart = textarea.selectionEnd = 1;
                textarea.focus();
                return;
            }
            if (cmd === '/aiusers') {
                textarea.value = ''; autoResize(textarea);
                const { showAiUsersOverview } = await import('../core/ai_users_overview.js');
                await showAiUsersOverview();
                textarea.focus();
                return;
            }
            // /me and /newTopic fall through to normal posting. A new topic resets
            // the AI's message context (server-side) but keeps the current AI focus,
            // so you stay in conversation with the same AI user after resetting.
        }

        // Detect AI user mention so we can show a waiting modal
        const users       = await getUsers();
        const aiUsers     = users.filter(u => u.is_ai);
        const mentions    = (text.match(/#(\S+)/g) || []).map(m => m.slice(1).toLowerCase());
        const explicitAi  = aiUsers.find(u => mentions.includes(u.name.toLowerCase()));

        // Explicitly mentioning an AI focuses the chat on it; focus mode then
        // auto-routes later plain messages to the same AI without re-mentioning.
        if (explicitAi) setFocus(explicitAi.name);

        const focus       = applyFocus(text, { chatPath: _chatPath, aiUsers, mentionedAi: explicitAi });
        text = focus.text;
        const mentionedAi = focus.mentionedAi;
        const hasAiMention = !!mentionedAi;

        let abortCtrl = null;

        if (hasAiMention) {
            abortCtrl = new AbortController();
            openAiModal(mentionedAi.name, () => abortCtrl.abort());
        }

        sendBtn.disabled = true;
        let res;
        try {
            res = await api.call('post_chat_message', { file: _chatPath, text }, 'POST', abortCtrl?.signal);
        } finally {
            sendBtn.disabled = false;
        }

        if (!res || res.aborted) { closeAiModal(); return; }

        if (res.success) {
            textarea.value = '';
            autoResize(textarea);
            renderChatView(_applyFullDataToWindow(res.data), _hasMore, true);
            // async_ai: server used fastcgi_finish_request — AI runs in background, keep
            // modal open until polling resolves the pending message.
            // No async_ai: AI already ran synchronously — close modal now.
            if (res.async_ai) {
                const pendingMsg = (res.data?.messages || []).slice().reverse().find(m => m.pending);
                if (pendingMsg) startStatusPoll(_chatPath, pendingMsg.id);
            } else {
                closeAiModal();
            }
        } else {
            closeAiModal();
            showToast(res.message || 'Failed to send', 'error');
        }
    };

    sendBtn.addEventListener('click', sendMessage);
    textarea.addEventListener('keydown', (e) => {
        if (e.altKey && (e.code === 'KeyC' || e.key === 'c' || e.key === 'C')) { // toggle "New Topic"
            e.preventDefault();
            if (newTopicChk) newTopicChk.checked = !newTopicChk.checked;
            return;
        }
        if (e.key === 'Enter' && !e.shiftKey) {
            if (!mentionPop.classList.contains('hidden')) return; // let autocomplete handle it
            e.preventDefault();
            sendMessage();
        } else if (e.key === 'Escape' && mentionPop.classList.contains('hidden') && !textarea.value && getFocusAi(_chatPath)) {
            e.preventDefault();
            setFocus(null);
        }
    });

    // Warm the cache and build the AI UID set for Markdown rendering
    getUsers().then(users => {
        _aiUids = new Set(users.filter(u => u.is_ai).map(u => u.uid));
    });

    // ── Topic lightbox ─────────────────────────────────────────────────────────
    const topicLightbox  = document.getElementById('chat-topic-lightbox');
    const topicInput     = document.getElementById('chat-topic-input');
    const topicSaveBtn   = document.getElementById('chat-topic-save-btn');
    const topicCancelBtn = document.getElementById('chat-topic-cancel-btn');
    const topicCloseBtn  = document.getElementById('chat-topic-close-btn');
    const topicEmojiBtn  = document.getElementById('chat-topic-emoji-btn');
    const topicEmojiPicker = document.getElementById('chat-topic-emoji-picker');

    if (topicLightbox) {
        EMOJIS.forEach(e => {
            const btn = document.createElement('button');
            btn.className = 'chat-emoji-item';
            btn.textContent = e;
            btn.addEventListener('click', () => {
                const p = topicInput.selectionStart;
                topicInput.value = topicInput.value.slice(0, p) + e + topicInput.value.slice(p);
                topicInput.selectionStart = topicInput.selectionEnd = p + e.length;
                topicInput.focus();
                topicEmojiPicker.classList.add('hidden');
            });
            topicEmojiPicker.appendChild(btn);
        });

        topicEmojiBtn.addEventListener('click', (ev) => {
            ev.stopPropagation();
            topicEmojiPicker.classList.toggle('hidden');
        });
        document.addEventListener('click', (ev) => {
            if (!topicEmojiPicker.contains(ev.target) && ev.target !== topicEmojiBtn) {
                topicEmojiPicker.classList.add('hidden');
            }
        });

        const closeTopicLightbox = () => topicLightbox.classList.add('hidden');

        const saveTopic = async () => {
            const topic = topicInput.value.trim();
            topicSaveBtn.disabled = true;
            const res = await api.call('update_chat_topic', { file: _chatPath, topic }, 'POST');
            topicSaveBtn.disabled = false;
            if (res.success) {
                state.currentChatData = res.data;
                updateTopicBar(topic);
                closeTopicLightbox();
            } else {
                showToast(res.message || 'Failed to update topic', 'error');
            }
        };

        topicSaveBtn.addEventListener('click', saveTopic);
        topicCancelBtn.addEventListener('click', closeTopicLightbox);
        topicCloseBtn.addEventListener('click', closeTopicLightbox);
        topicLightbox.addEventListener('click', (e) => { if (e.target === topicLightbox) closeTopicLightbox(); });
        topicInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeTopicLightbox();
        });

        const topicBtn = document.getElementById('chat-topic-btn');
        if (topicBtn) {
            topicBtn.addEventListener('click', () => {
                topicInput.value = state.currentChatData?.topic || '';
                topicEmojiPicker.classList.add('hidden');
                topicLightbox.classList.remove('hidden');
                setTimeout(() => topicInput.focus(), 50);
            });
        }
    }
};
