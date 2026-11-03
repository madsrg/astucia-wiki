// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// Saving without silently overwriting somebody else.
//
// `get` hands out a `rev` (kept in state.currentPageRev); every editor save sends it back
// as `base`, and the server answers 409 if the page has moved on since — another person,
// an AI user, a workflow, an rsync. Before this, the open-page watcher's toast was the
// only warning, and the second save simply won.
//
// The editors (page_edit, json_view, list/data, diagram) each own their success path, so
// this module only does the two shared parts: the request, and the dialog that asks what
// to do with a conflict.
import { state } from './state.js';
import { icons } from './icons.js';
import { diffLines, collapseContext } from './diff.js';
import { t } from '../i18n/index.js';

/**
 * POST a page's content to `save`, based on the rev it was loaded at.
 * Resolves to `{ ok, conflict, data }` — `data` is the parsed JSON body either way, so a
 * conflict carries what is on disk now (`data`, `rev`, `lastUpdated`, `size`, `modifiedBy`).
 * Throws on a network failure or a non-JSON answer, like the fetches it replaces.
 */
export const postPageSave = async (path, body, { contentType = 'text/plain', force = false } = {}) => {
    const qs = new URLSearchParams({ action: 'save', file: path });
    if (state.currentSpace) qs.set('space', state.currentSpace);
    // Only the open page has a rev we can vouch for; a save of any other path is unchecked.
    if (state.currentPageRev && path === state.currentPagePath) qs.set('base', state.currentPageRev);
    if (force) qs.set('force', '1');
    const res  = await fetch(`api.php?${qs}`, { method: 'POST', headers: { 'Content-Type': contentType }, body });
    const data = await res.json();
    return { ok: res.ok && !!data.success, conflict: res.status === 409 && !!data.conflict, data };
};

let _overlay = null;

const build = () => {
    _overlay = document.createElement('div');
    _overlay.id = 'save-conflict-modal';
    _overlay.className = 'lightbox-overlay hidden';
    _overlay.innerHTML = `
        <div class="lightbox-content save-conflict-content" role="dialog" aria-modal="true" aria-labelledby="save-conflict-title">
            <div class="input-modal-header">
                <span class="input-modal-icon">${icons.warning}</span>
                <h3 id="save-conflict-title"></h3>
            </div>
            <p class="confirm-modal-message save-conflict-message"></p>
            <div class="save-conflict-legend">
                <span class="git-diff-line-del save-conflict-key"></span>
                <span class="git-diff-line-add save-conflict-key"></span>
            </div>
            <pre class="git-diff-content save-conflict-diff"></pre>
            <div class="lightbox-footer">
                <button class="btn btn-secondary" data-choice="keep"></button>
                <button class="btn btn-secondary" data-choice="theirs"></button>
                <button class="btn btn-danger"    data-choice="overwrite"></button>
            </div>
        </div>`;
    document.body.appendChild(_overlay);
};

/**
 * Show what changed and ask what to do.
 * `theirs` is what is on disk now, `mine` what this editor tried to save.
 * Resolves to 'keep' (back to editing, nothing written), 'theirs' (drop mine, show
 * theirs) or 'overwrite' (save mine over theirs). Escape and the backdrop mean 'keep',
 * the one choice that loses nothing.
 */
export const askSaveConflict = ({ mine, theirs, modifiedBy = null, lastUpdated = null, allowTheirs = true }) =>
    new Promise(resolve => {
        if (!_overlay) build();
        const time = lastUpdated ? new Date(lastUpdated * 1000).toLocaleString() : '';
        _overlay.querySelector('#save-conflict-title').textContent = t('conflict.title');
        _overlay.querySelector('.save-conflict-message').textContent = modifiedBy
            ? t('conflict.message-by', { name: modifiedBy, time })
            : t('conflict.message', { time });
        const [delKey, addKey] = _overlay.querySelectorAll('.save-conflict-key');
        delKey.textContent = '− ' + t('conflict.legend-theirs');
        addKey.textContent = '+ ' + t('conflict.legend-mine');

        const pre = _overlay.querySelector('.save-conflict-diff');
        pre.textContent = '';
        for (const l of collapseContext(diffLines(theirs, mine))) {
            const span = document.createElement('span');
            span.className = 'git-diff-line';
            if (l.type === '…') {
                span.classList.add('git-diff-line-hunk');
                span.textContent = t('conflict.unchanged-lines', { count: l.count });
            } else {
                if (l.type === '-') span.classList.add('git-diff-line-del');
                if (l.type === '+') span.classList.add('git-diff-line-add');
                span.textContent = `${l.type} ${l.text}`;
            }
            pre.appendChild(span);
        }

        const buttons = _overlay.querySelectorAll('[data-choice]');
        buttons.forEach(b => { b.textContent = t(`conflict.${b.dataset.choice}`); });
        _overlay.querySelector('[data-choice="theirs"]').classList.toggle('hidden', !allowTheirs);

        const close = choice => {
            _overlay.classList.add('hidden');
            _overlay.removeEventListener('click', onClick);
            document.removeEventListener('keydown', onKey, true);
            resolve(choice);
        };
        const onClick = e => {
            if (e.target === _overlay) return close('keep');
            const choice = e.target.closest('[data-choice]')?.dataset.choice;
            if (choice) close(choice);
        };
        // Capture phase and stopped: the editor's own Escape and Ctrl+S handlers sit
        // underneath this dialog and must not act on a key meant for it.
        const onKey = e => {
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close('keep'); }
        };
        _overlay.addEventListener('click', onClick);
        document.addEventListener('keydown', onKey, true);
        _overlay.classList.remove('hidden');
        setTimeout(() => _overlay.querySelector('[data-choice="keep"]').focus(), 50);
    });

/**
 * Put `text` on the clipboard before it is dropped from an editor.
 * False when the browser refuses (no permission, not a secure context) — the caller must
 * then keep the text rather than lose it.
 */
export const stashOnClipboard = async (text) => {
    try { await navigator.clipboard.writeText(text); return true; }
    catch { return false; }
};
