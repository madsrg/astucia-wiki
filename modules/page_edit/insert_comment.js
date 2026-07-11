import { insertMarkdown } from './editor.js';
import { getUsers, getMentionableUsers } from '../core/users.js';

const EMOJIS = ['😀','😂','😍','🤔','😢','😮','😡','👍','👎','👋','🙏','❤️','🎉','🔥','✅','❌','⭐','💡','🚀','📝','🎯','👀','💬','🤝'];

export const openCommentLightbox = () => {
    const lb    = document.getElementById('comment-lightbox');
    const input = document.getElementById('comment-input');
    if (!lb) return;
    lb.classList.remove('hidden');
    input.value = '';
    input.focus();
};

export const init = () => {
    const lb          = document.getElementById('comment-lightbox');
    const input       = document.getElementById('comment-input');
    const emojiBtn    = document.getElementById('comment-emoji-btn');
    const emojiPicker = document.getElementById('comment-emoji-picker');
    const mentionPop  = document.getElementById('comment-mention-popup');
    const confirmBtn  = document.getElementById('comment-confirm-btn');
    const cancelBtn   = document.getElementById('comment-cancel-btn');
    const closeBtn    = document.getElementById('comment-close-btn');
    if (!lb) return;

    // ── Emoji picker ────────────────────────────────────────────────────────────
    EMOJIS.forEach(e => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'chat-emoji-item';
        btn.textContent = e;
        btn.addEventListener('click', () => {
            const p = input.selectionStart;
            input.value = input.value.slice(0, p) + e + input.value.slice(p);
            input.selectionStart = input.selectionEnd = p + e.length;
            input.focus();
            emojiPicker.classList.add('hidden');
        });
        emojiPicker.appendChild(btn);
    });

    emojiBtn.addEventListener('click', (ev) => { ev.stopPropagation(); emojiPicker.classList.toggle('hidden'); });
    document.addEventListener('click', (ev) => {
        if (!emojiPicker.contains(ev.target) && ev.target !== emojiBtn) emojiPicker.classList.add('hidden');
    });

    // ── Mention autocomplete ────────────────────────────────────────────────────
    input.addEventListener('input', async () => {
        const val = input.value;
        const pos = input.selectionStart;
        let start = pos - 1;
        while (start >= 0 && val[start] !== '#' && val[start] !== ' ' && val[start] !== '\n') start--;
        if (start < 0 || val[start] !== '#') { mentionPop.classList.add('hidden'); return; }
        const query   = val.slice(start + 1, pos).toLowerCase();
        const matches = (await getMentionableUsers()).filter(u => u.name.toLowerCase().startsWith(query)).slice(0, 6);
        if (!matches.length) { mentionPop.classList.add('hidden'); return; }
        mentionPop.innerHTML = '';
        matches.forEach(u => {
            const item = document.createElement('div');
            item.className = 'chat-mention-item';
            item.textContent = '#' + u.name;
            item.addEventListener('mousedown', (e) => {
                e.preventDefault();
                const insert = '#' + u.name + ' ';
                input.value = input.value.slice(0, start) + insert + input.value.slice(pos);
                input.selectionStart = input.selectionEnd = start + insert.length;
                mentionPop.classList.add('hidden');
            });
            mentionPop.appendChild(item);
        });
        mentionPop.classList.remove('hidden');
    });
    input.addEventListener('blur', () => setTimeout(() => mentionPop.classList.add('hidden'), 150));
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') close();
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) confirm();
    });

    // ── Open / close ────────────────────────────────────────────────────────────
    const close = () => lb.classList.add('hidden');
    const confirm = async () => {
        const text = input.value.trim();
        if (!text) return;
        const uid     = window.WIKI_USER_UID ?? 0;
        const encoded = btoa(unescape(encodeURIComponent(text)));

        // Collect UIDs of users mentioned via #Name in the comment text
        // (API accounts excluded — they can't be notified).
        const users = await getMentionableUsers();
        const mentionedUids = [];
        const mentionRe = /#(\S+)/g;
        let m;
        while ((m = mentionRe.exec(text)) !== null) {
            const user = users.find(u => u.name.toLowerCase() === m[1].toLowerCase());
            if (user?.uid && !mentionedUids.includes(user.uid)) mentionedUids.push(user.uid);
        }

        insertMarkdown(`{user_comment:${uid}:${encoded}:${mentionedUids.join(',')}}`);
        close();
    };

    closeBtn.addEventListener('click', close);
    cancelBtn.addEventListener('click', close);
    confirmBtn.addEventListener('click', confirm);
    lb.addEventListener('click', (e) => { if (e.target === lb) close(); });

    getUsers(); // warm the cache on init
};
