import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { showToast, confirmModal } from '../core/utils.js';
import { getUsers, getMentionableUsers } from '../core/users.js';
import { getMcpServers } from '../core/mcp_servers.js';
import { t } from '../i18n/index.js';
import { openAiModal, closeAiModal, checkAiModal, startStatusPoll } from '../core/ai_modal.js';
import { getFocusAi, setFocusAi, applyFocus, createFocusChip } from '../core/chat_focus.js';

const POLL_MS = 5000;
const EMOJIS  = ['😀','😂','😍','🤔','😢','😮','😡','👍','👎','👋','🙏','❤️','🎉','🔥','✅','❌','⭐','💡','🚀','📝','🎯','👀','💬','🤝'];
const CHAT_COMMANDS = [
    { name: 'newTopic',  description: t('chat.cmd.new-topic') },
    { name: 'me',        description: t('chat.cmd.me') },
    { name: 'topic',     description: t('chat.cmd.topic') },
    { name: 'purge',     description: t('chat.cmd.purge') },
    { name: 'summarize', description: t('chat.cmd.summarize') },
    { name: 'aiUsers',   description: t('chat.cmd.ai-users') },
];

let _pollTimer      = null;
let _lastMtime      = 0;
let _linkedMdMtime  = 0;
let _pcData         = null;
let _pcPath         = null; // path currently loaded in the panel
let _aiUids         = new Set();
let _focusChip      = null;

// ── Focus mode ──────────────────────────────────────────────────────────────

const updateFocus = () => { if (_focusChip) _focusChip.update(getFocusAi(_pcPath)); };

const setFocus = (name) => { setFocusAi(_pcPath, name); updateFocus(); };

// Clicking an AI's avatar or name focuses the chat on it.
const attachFocusClick = (el, msg) => {
    if (!_aiUids.has(msg.uid)) return;
    el.classList.add('chat-avatar-focusable');
    el.title = t('chat.focus.start', { name: msg.name });
    el.addEventListener('click', () => setFocus(msg.name));
};

// ── Helpers ───────────────────────────────────────────────────────────────────

const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const avatarColor = uid => {
    const p = ['#4a90d9','#7c3aed','#059669','#d97706','#dc2626','#0891b2','#9333ea','#b45309'];
    return p[(uid ?? 0) % p.length];
};

const formatTime = ts => {
    const d = new Date(ts), now = new Date();
    if (d.toDateString() === now.toDateString())
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' '
         + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

const renderText = (raw, isAi = false) => {
    if (isAi && typeof marked !== 'undefined') {
        const html = marked.parse(String(raw ?? ''));
        // Highlight #mentions, but skip HTML entities so numeric ones like &#39;
        // (marked's output for an apostrophe) aren't mangled by matching "#39".
        return html.replace(/(&#?\w+;)|#(\w+)/g, (_, ent, name) => ent || `<span class="chat-mention">#${name}</span>`);
    }
    return esc(raw).replace(/#(\S+)/g, '<span class="chat-mention">#$1</span>');
};

const isNearBottom = el => el.scrollHeight - el.scrollTop - el.clientHeight < 80;

// ── Row builder ───────────────────────────────────────────────────────────────

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
    const isMe = msg.uid === currentUid;

    const row = document.createElement('div');
    row.className = 'chat-row pc-row' + (isMe ? ' chat-row-mine' : '');
    row.dataset.id = msg.id;

    const avatarEl = document.createElement('div');
    if (!grouped) {
        avatarEl.className = 'chat-avatar pc-avatar';
        avatarEl.textContent = (msg.name || '?')[0].toUpperCase();
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
        meta.innerHTML = `<span class="chat-name">${esc(msg.name)}</span><span class="chat-time">${formatTime(msg.timestamp)}</span>`;
        attachFocusClick(meta.querySelector('.chat-name'), msg);
        col.appendChild(meta);
    }

    const isAiMsg = _aiUids.has(msg.uid);
    const bubble = document.createElement('div');
    bubble.className = 'chat-bubble' + (isMe ? ' chat-bubble-mine' : '') + (isAiMsg ? ' chat-bubble-md' : '');

    if (msg.pending) {
        const age = Date.now() - new Date(msg.timestamp).getTime();
        const TIMEOUT_MS = 300_000;
        if (age >= TIMEOUT_MS) {
            bubble.innerHTML = `<span class="chat-pending-timeout">${t('chat.timeout')}</span>`;
        } else {
            bubble.innerHTML = `<span class="chat-pending-indicator"><span class="chat-spinner"></span>${t('chat.working')}</span>`;
            const cancelBtn = document.createElement('button');
            cancelBtn.className = 'btn btn-sm btn-secondary chat-pending-cancel';
            cancelBtn.textContent = t('btn.cancel');
            cancelBtn.addEventListener('click', async () => {
                cancelBtn.disabled = true;
                const res = await api.call('cancel_pending_chat_message', { file: _pcPath, id: msg.id }, 'POST');
                if (res.success) {
                    _pcData = res.data;
                    renderMessages(_pcData.messages || [], false);
                }
            });
            bubble.appendChild(cancelBtn);
            const capturedPath = _pcPath;
            setTimeout(() => {
                if (_pcPath === capturedPath && _pcData) renderMessages(_pcData.messages || [], false);
            }, TIMEOUT_MS - age + 500);
        }
        col.appendChild(bubble);
        row.appendChild(col);
        return row;
    }

    bubble.innerHTML = renderText(msg.text, isAiMsg);

    if (isMe || currentRole === 'admin') {
        const del = document.createElement('button');
        del.className = 'chat-del-btn';
        del.title = t('chat.delete-title');
        del.innerHTML = '&times;';
        del.addEventListener('click', async () => {
            const ok = await confirmModal(t('chat.delete-confirm'), { confirmLabel: t('btn.delete'), dangerous: true });
            if (!ok) return;
            const res = await api.call('delete_chat_message', { file: _pcPath, id: msg.id }, 'POST');
            if (res.success) {
                _pcData = res.data;
                renderMessages(_pcData.messages || [], false);
            } else {
                showToast(res.message || 'Failed to delete', 'error');
            }
        });
        bubble.appendChild(del);
    }

    col.appendChild(bubble);
    row.appendChild(col);
    return row;
};

// ── Render ────────────────────────────────────────────────────────────────────

const renderMessages = (messages, scrollToBottom = true) => {
    const container = document.getElementById('pc-messages');
    if (!container) return;
    container.innerHTML = '';
    if (!messages.length) {
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

const appendMessages = (newMsgs, prevUid) => {
    const container = document.getElementById('pc-messages');
    if (!container) return;
    const placeholder = container.querySelector('.chat-empty');
    if (placeholder) placeholder.remove();
    const shouldScroll = isNearBottom(container);
    newMsgs.forEach(msg => {
        container.appendChild(buildRow(msg, msg.uid === prevUid));
        prevUid = msg.uid;
    });
    if (shouldScroll) container.scrollTop = container.scrollHeight;
    checkAiModal(_pcData?.messages || []);
};

// ── Polling ───────────────────────────────────────────────────────────────────

const stopPoll = () => {
    if (_pollTimer) { clearInterval(_pollTimer); _pollTimer = null; }
};

const startPoll = (path, initialMtime = 0) => {
    _lastMtime = initialMtime;
    stopPoll();
    const linkedMd = path.replace(/\.chat$/, '.md');
    _pollTimer = setInterval(async () => {
        if (_pcPath !== path) { stopPoll(); return; }
        const msgs  = _pcData?.messages || [];
        const maxId = msgs.length ? msgs[msgs.length - 1].id : 0;
        const res   = await api.call('chat_messages', { file: path, since_id: maxId });
        if (!res.success) return;
        const newMsgs = res.messages || [];
        if (newMsgs.length) {
            _lastMtime = res.mtime || _lastMtime;
            const prevUid = msgs.length ? msgs[msgs.length - 1].uid : null;
            _pcData = { ..._pcData, messages: [...msgs, ...newMsgs] };
            appendMessages(newMsgs, prevUid);
        } else if ((res.mtime || 0) > _lastMtime) {
            _lastMtime = res.mtime;
            const full = await api.call('get', { file: path });
            if (!full.success) return;
            try {
                const fullData = JSON.parse(full.data);
                _pcData = fullData;
                renderMessages(fullData.messages || [], false);
                checkAiModal(_pcData.messages || []);
            } catch { /* ignore parse errors */ }
        }
        // Check if the linked .md page was updated by the AI and refresh if so
        const mtRes = await api.call('file_mtime', { file: linkedMd });
        if (mtRes.success && mtRes.mtime > _linkedMdMtime) {
            _linkedMdMtime = mtRes.mtime;
            const pageView = await import('../page_view/index.js');
            await pageView.refreshPageContent();
            showToast(t('page-chat.page-updated'), 'info');
        }
    }, POLL_MS);
};

// ── Input / send ──────────────────────────────────────────────────────────────

const autoResize = el => {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 100) + 'px';
};

const doSend = async () => {
    const textarea = document.getElementById('pc-input');
    const sendBtn  = document.getElementById('pc-send-btn');
    if (!textarea || !sendBtn || !_pcPath) return;
    let text = textarea.value.trim();
    if (!text) return;

    if (text.startsWith('/')) {
        const spaceIdx = text.indexOf(' ');
        const cmd = (spaceIdx === -1 ? text : text.slice(0, spaceIdx)).toLowerCase();
        const arg = spaceIdx === -1 ? '' : text.slice(spaceIdx + 1).trim();
        if (cmd === '/topic') {
            if (!arg) { showToast(t('chat.cmd.topic-usage'), 'error'); return; }
            textarea.value = ''; autoResize(textarea);
            const res = await api.call('update_chat_topic', { file: _pcPath, topic: arg }, 'POST');
            if (res.success) { _pcData = res.data; renderMessages(_pcData.messages || [], false); }
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
            const res = await api.call('purge_chat_messages', { file: _pcPath, keep }, 'POST');
            if (res.success) { _pcData = res.data; renderMessages(_pcData.messages || [], false); }
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
            const aiList = (await getUsers()).filter(u => u.is_ai);
            await confirmModal(t('chat.cmd.ai-users-title'), {
                message: aiList.length ? aiList.map(u => '#' + u.name).join(', ') : t('chat.cmd.ai-users-none'),
                confirmLabel: t('chat.cmd.ai-users-close'),
                hideCancel: true,
            });
            textarea.focus();
            return;
        }
        // /me and /newTopic fall through to normal posting; a new topic ends
        // the focused conversation.
        if (cmd === '/newtopic') setFocus(null);
    }

    const users = await getUsers();
    const aiUsers = users.filter(u => u.is_ai);
    const mentions = (text.match(/#(\S+)/g) || []).map(m => m.slice(1).toLowerCase());
    const explicitAi = aiUsers.find(u => mentions.includes(u.name.toLowerCase()));

    // Explicitly mentioning an AI focuses the chat on it; focus mode then
    // auto-routes later plain messages to the same AI without re-mentioning.
    if (explicitAi) setFocus(explicitAi.name);

    const focus = applyFocus(text, { chatPath: _pcPath, aiUsers, mentionedAi: explicitAi });
    text = focus.text;
    const mentionedAi = focus.mentionedAi;

    let abortCtrl = null;
    if (mentionedAi) {
        abortCtrl = new AbortController();
        openAiModal(mentionedAi.name, () => abortCtrl.abort());
    }

    sendBtn.disabled = true;
    let res;
    try {
        res = await api.call('post_chat_message', { file: _pcPath, text }, 'POST', abortCtrl?.signal);
    } finally {
        sendBtn.disabled = false;
    }

    if (!res || res.aborted) { closeAiModal(); return; }

    if (res.success) {
        textarea.value = '';
        autoResize(textarea);
        _pcData = res.data;
        renderMessages(_pcData.messages || [], true);
        if (res.async_ai) {
            const pendingMsg = (_pcData.messages || []).slice().reverse().find(m => m.pending);
            if (pendingMsg) startStatusPoll(_pcPath, pendingMsg.id);
        } else {
            closeAiModal();
        }
    } else {
        closeAiModal();
        showToast(res.message || t('page-chat.send-failed'), 'error');
    }
};

const setupInput = () => {
    const textarea   = document.getElementById('pc-input');
    const sendBtn    = document.getElementById('pc-send-btn');
    const mentionPop = document.getElementById('pc-mention-popup');
    const emojiBtn   = document.getElementById('pc-emoji-btn');
    const emojiPicker = document.getElementById('pc-emoji-picker');
    if (!textarea || !sendBtn || !mentionPop) return;

    const inputArea = textarea.closest('.chat-input-area');
    if (inputArea) _focusChip = createFocusChip(inputArea, textarea, { onExit: () => setFocus(null) });

    if (emojiBtn && emojiPicker) {
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
        emojiBtn.addEventListener('click', ev => { ev.stopPropagation(); emojiPicker.classList.toggle('hidden'); });
        document.addEventListener('click', ev => {
            if (!emojiPicker.contains(ev.target) && ev.target !== emojiBtn) emojiPicker.classList.add('hidden');
        });
    }

    let triggerStart = -1, triggerChar = '', selectedIdx = -1;
    const getItems = () => Array.from(mentionPop.querySelectorAll('.chat-mention-item'));
    const closePop = () => {
        mentionPop.classList.add('hidden');
        mentionPop.classList.remove('chat-mention-popup-cmd');
        triggerStart = -1; triggerChar = ''; selectedIdx = -1;
    };
    const setSelected = idx => {
        getItems().forEach((el, i) => el.classList.toggle('chat-mention-item-active', i === idx));
        selectedIdx = idx;
    };

    textarea.addEventListener('input', async () => {
        autoResize(textarea);
        const val = textarea.value, pos = textarea.selectionStart;

        // "src:<slug>" is a word-prefix trigger (unlike the single-char # / triggers
        // below) — explicitly forces an AI reply to use only that MCP server's tools.
        let wordStart = pos - 1;
        while (wordStart >= 0 && val[wordStart] !== ' ' && val[wordStart] !== '\n') wordStart--;
        wordStart++;
        const word = val.slice(wordStart, pos);
        if (/^src:/i.test(word)) {
            triggerStart = wordStart; triggerChar = 'src:';
            const query = word.slice(4).toLowerCase();
            selectedIdx = -1;
            mentionPop.innerHTML = '';
            mentionPop.classList.add('chat-mention-popup-cmd');
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
                item.addEventListener('mousedown', e => {
                    e.preventDefault();
                    const curPos = textarea.selectionStart;
                    const insert = 'src:' + s.slug + ' ';
                    textarea.value = textarea.value.slice(0, triggerStart) + insert + textarea.value.slice(curPos);
                    textarea.selectionStart = textarea.selectionEnd = triggerStart + insert.length;
                    closePop();
                });
                mentionPop.appendChild(item);
            });
            mentionPop.classList.remove('hidden');
            return;
        }

        let start = pos - 1;
        while (start >= 0 && val[start] !== '#' && val[start] !== '/' && val[start] !== ' ' && val[start] !== '\n') start--;
        if (start < 0 || (val[start] !== '#' && val[start] !== '/')) { closePop(); return; }
        if (val[start] === '/' && start !== 0) { closePop(); return; }
        triggerStart = start; triggerChar = val[start];
        const query = val.slice(start + 1, pos).toLowerCase();
        selectedIdx = -1;
        mentionPop.innerHTML = '';

        if (triggerChar === '#') {
            mentionPop.classList.remove('chat-mention-popup-cmd');
            const matches = (await getMentionableUsers()).filter(u => u.name.toLowerCase().startsWith(query)).slice(0, 6);
            if (!matches.length) { closePop(); return; }
            matches.forEach(u => {
                const item = document.createElement('div');
                item.className = 'chat-mention-item';
                item.textContent = '#' + u.name;
                item.addEventListener('mousedown', e => {
                    e.preventDefault();
                    const curPos = textarea.selectionStart;
                    const insert = '#' + u.name + ' ';
                    textarea.value = textarea.value.slice(0, triggerStart) + insert + textarea.value.slice(curPos);
                    textarea.selectionStart = textarea.selectionEnd = triggerStart + insert.length;
                    closePop();
                });
                mentionPop.appendChild(item);
            });
        } else {
            mentionPop.classList.add('chat-mention-popup-cmd');
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
                item.addEventListener('mousedown', e => {
                    e.preventDefault();
                    const curPos = textarea.selectionStart;
                    const insert = '/' + c.name + ' ';
                    textarea.value = textarea.value.slice(0, triggerStart) + insert + textarea.value.slice(curPos);
                    textarea.selectionStart = textarea.selectionEnd = triggerStart + insert.length;
                    closePop();
                });
                mentionPop.appendChild(item);
            });
        }
        mentionPop.classList.remove('hidden');
    });

    textarea.addEventListener('blur', () => setTimeout(closePop, 150));
    textarea.addEventListener('keydown', e => {
        if (!mentionPop.classList.contains('hidden')) {
            const items = getItems();
            if (e.key === 'ArrowDown') { e.preventDefault(); setSelected(Math.min(selectedIdx + 1, items.length - 1)); return; }
            if (e.key === 'ArrowUp')   { e.preventDefault(); setSelected(Math.max(selectedIdx - 1, 0)); return; }
            if (e.key === 'Tab' || e.key === 'Enter') {
                e.preventDefault();
                const active = selectedIdx >= 0 ? items[selectedIdx] : items[0];
                if (active) active.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
                return;
            }
            if (e.key === 'Escape') { closePop(); return; }
        }
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSend(); }
        else if (e.key === 'Escape' && !textarea.value && getFocusAi(_pcPath)) { e.preventDefault(); setFocus(null); }
    });

    sendBtn.addEventListener('click', doSend);
};

// ── Panel open/close ──────────────────────────────────────────────────────────

export const closePanel = () => {
    stopPoll();
    closeAiModal();
    _pcPath = null;
    updateFocus();
    _linkedMdMtime = 0;
    state.pageChatPath = null;
    const panel = document.getElementById('page-chat-panel');
    if (panel) panel.classList.remove('open');
    _updateChatBtnState(false);
    document.getElementById('pc-page-input-area')?.classList.add('hidden');
    // Restore meta row when closing on an md page that isn't in edit mode
    if (state.currentPagePath?.endsWith('.md') && !state.isEditing) {
        document.getElementById('page-meta-row')?.classList.remove('hidden');
    }
};

const _updateChatBtnState = (isOpen) => {
    const btn = document.getElementById('page-chat-btn');
    if (btn) btn.classList.toggle('toc-btn-active', isOpen);
};

const _expandSidebarIfCollapsed = () => {
    const container = document.querySelector('.app-container');
    if (!container?.classList.contains('sidebar-collapsed')) return 0;
    const btn = document.getElementById('sidebar-toggle-btn');
    container.classList.remove('sidebar-collapsed');
    if (btn) {
        btn.innerHTML = '&#x2039;';
        btn.title = t('nav.collapse');
    }
    localStorage.setItem('sidebarCollapsed', 'false');
    return 220; // ms — sidebar CSS transition is 0.2s
};

const _matchSidebarWidth = () => {
    const sidebar = document.querySelector('.sidebar');
    const panel   = document.getElementById('page-chat-panel');
    if (sidebar && panel) panel.style.width = sidebar.offsetWidth + 'px';
};

const loadAndOpen = async (chatPath) => {
    const delay = _expandSidebarIfCollapsed();
    if (delay) await new Promise(r => setTimeout(r, delay));
    _matchSidebarWidth();

    _pcPath = chatPath;
    state.pageChatPath = chatPath;

    // Snapshot the linked .md mtime so we only react to changes made after the panel opens
    const linkedMd = chatPath.replace(/\.chat$/, '.md');
    const mtRes = await api.call('file_mtime', { file: linkedMd });
    _linkedMdMtime = mtRes.mtime || 0;

    // Cache AI user UIDs so renderText can render their messages as Markdown
    const users = await getUsers();
    _aiUids = new Set(users.filter(u => u.is_ai).map(u => u.uid));
    updateFocus();

    // Swap meta row (attachments/tags) for the page chat input
    document.getElementById('page-meta-row')?.classList.add('hidden');
    document.getElementById('pc-page-input-area')?.classList.remove('hidden');

    const panel = document.getElementById('page-chat-panel');
    if (panel) {
        panel.classList.add('open');
        const titleEl = document.getElementById('pc-panel-title');
        if (titleEl) titleEl.textContent = chatPath.split('/').pop().replace(/\.chat$/, '');
    }
    _updateChatBtnState(true);

    const container = document.getElementById('pc-messages');
    if (container) container.innerHTML = `<p class="chat-empty" style="opacity:.5">Loading…</p>`;

    const res = await api.call('chat_messages', { file: chatPath });
    if (!res.success) { showToast(res.message || t('page-chat.load-failed'), 'error'); return; }

    _pcData = { messages: res.messages || [], topic: res.topic || '' };
    renderMessages(_pcData.messages, true);
    startPoll(chatPath, res.mtime || 0);
    document.getElementById('pc-input')?.focus();
};

const createAndOpen = async (chatPath) => {
    const res = await api.call('create_chat', { path: chatPath, topic: '', git_commit: '0' }, 'POST');
    if (!res.success) { showToast(res.message || t('page-chat.create-failed'), 'error'); return; }
    await loadAndOpen(chatPath);
};

// ── Confirm lightbox ──────────────────────────────────────────────────────────

const showCreateConfirm = (chatPath) => {
    const lb = document.getElementById('page-chat-confirm-lightbox');
    if (!lb) return;

    const nameEl = document.getElementById('pcl-chat-name');
    if (nameEl) nameEl.textContent = chatPath.split('/').pop();

    const confirmBtn = document.getElementById('pcl-confirm-btn');
    const cancelBtn  = document.getElementById('pcl-cancel-btn');
    const closeBtn   = document.getElementById('pcl-close-btn');

    // Replace buttons to remove stale listeners
    const fresh = (el) => { const c = el.cloneNode(true); el.parentNode.replaceChild(c, el); return c; };
    const fc = fresh(confirmBtn), fl = fresh(cancelBtn), fx = fresh(closeBtn);

    const close = () => lb.classList.add('hidden');
    fc.addEventListener('click', async () => { close(); await createAndOpen(chatPath); });
    fl.addEventListener('click', close);
    fx.addEventListener('click', close);
    lb.onclick = e => { if (e.target === lb) close(); };

    lb.classList.remove('hidden');
};

// ── Public API ────────────────────────────────────────────────────────────────

export const openPageChat = async (pagePath) => {
    // Toggle: if the panel is already showing this page's chat, close it
    const chatPath = pagePath.replace(/\.md$/i, '.chat');
    if (_pcPath === chatPath) { closePanel(); return; }

    const res = await api.call('exists', { file: chatPath });
    if (res.exists) {
        await loadAndOpen(chatPath);
    } else {
        showCreateConfirm(chatPath);
    }
};

// ── Init ──────────────────────────────────────────────────────────────────────

export const init = () => {
    document.getElementById('pc-close-btn')?.addEventListener('click', closePanel);

    document.getElementById('page-chat-btn')?.addEventListener('click', () => {
        if (state.currentPagePath?.endsWith('.md')) {
            openPageChat(state.currentPagePath);
        }
    });

    setupInput();
};
