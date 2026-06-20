import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { showToast, confirmModal } from '../core/utils.js';
import { t } from '../i18n/index.js';
import { icons } from '../core/icons.js';

const escHtml = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const formatRelTime = (ts) => {
    const diff = Math.floor(Date.now() / 1000) - ts;
    if (diff < 60)          return 'just now';
    if (diff < 3600)        return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400)       return Math.floor(diff / 3600) + 'h ago';
    if (diff < 86400 * 30)  return Math.floor(diff / 86400) + 'd ago';
    return new Date(ts * 1000).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
};

// ── Public API ─────────────────────────────────────────────────────────────────

export const checkSpaceGit = async () => {
    const res = await api.call('git_status');
    state.currentSpaceHasGit = res.success && (res.data?.has_git === true);
    _updateHistoryBtn();
};

export const updateForPage = () => {
    _updateHistoryBtn();
};

// Called by page_view after every file load with the git_commit setting from the server.
export const updateGitButtons = (gitCommit) => {
    state.currentFileGitCommit = gitCommit;
    _updateHistoryBtn();
    _updateToggleBtn(gitCommit);
    _updateSnapshotBtn(gitCommit);
};

// ── Private ────────────────────────────────────────────────────────────────────

const _updateHistoryBtn = () => {
    const btn = document.getElementById('git-history-btn');
    if (!btn) return;
    btn.classList.toggle('hidden', !state.currentSpaceHasGit || !state.currentPagePath);
};

const _updateToggleBtn = (gitCommit) => {
    const btn = document.getElementById('git-commit-toggle-btn');
    if (!btn) return;
    const hasGit = state.currentSpaceHasGit && !!state.currentPagePath;
    btn.classList.toggle('hidden', !hasGit);
    btn.classList.toggle('git-tracking-on', !!gitCommit);
    btn.title = gitCommit ? t('git.tracking-on') : t('git.tracking-off');
};

const _updateSnapshotBtn = (gitCommit) => {
    const btn = document.getElementById('git-snapshot-btn');
    if (!btn) return;
    const type = state.currentPageType;
    const isManual = type === 'chat' || type === 'list';
    btn.classList.toggle('hidden', !state.currentSpaceHasGit || !isManual || !gitCommit);
};

// ── Diff rendering ─────────────────────────────────────────────────────────────

const renderDiff = (raw) => {
    const pre = document.getElementById('git-diff-content');
    pre.innerHTML = '';
    const lines = raw.split('\n');
    for (const line of lines) {
        const span = document.createElement('span');
        span.className = 'git-diff-line';
        if (line.startsWith('+') && !line.startsWith('+++')) {
            span.classList.add('git-diff-line-add');
        } else if (line.startsWith('-') && !line.startsWith('---')) {
            span.classList.add('git-diff-line-del');
        } else if (line.startsWith('@@')) {
            span.classList.add('git-diff-line-hunk');
        } else if (line.startsWith('diff ') || line.startsWith('index ') ||
                   line.startsWith('---')    || line.startsWith('+++') ||
                   line.startsWith('new file') || line.startsWith('deleted file')) {
            span.classList.add('git-diff-line-meta');
        }
        span.textContent = line;
        pre.appendChild(span);
    }
};

// ── Init ───────────────────────────────────────────────────────────────────────

export const init = () => {
    const lightbox  = document.getElementById('git-history-lightbox');
    const closeBtn  = document.getElementById('git-history-close-btn');
    const histBtn   = document.getElementById('git-history-btn');
    const list      = document.getElementById('git-history-list');
    const subtitle  = document.getElementById('git-history-filename');
    const diffView  = document.getElementById('git-diff-view');
    const diffBack  = document.getElementById('git-diff-back-btn');
    const diffMeta  = document.getElementById('git-diff-meta');
    if (!lightbox) return;

    const showList = () => {
        list.classList.remove('hidden');
        diffView.classList.add('hidden');
    };
    const showDiff = () => {
        list.classList.add('hidden');
        diffView.classList.remove('hidden');
    };

    const close = () => { lightbox.classList.add('hidden'); showList(); };
    closeBtn.addEventListener('click', close);
    lightbox.addEventListener('click', (e) => { if (e.target === lightbox) close(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !lightbox.classList.contains('hidden')) close();
    });
    diffBack.addEventListener('click', showList);

    histBtn.addEventListener('click', async () => {
        const path = state.currentPagePath;
        if (!path) return;

        if (subtitle) subtitle.textContent = path;
        showList();

        list.innerHTML = `<p class="git-history-loading">${t('git.loading')}</p>`;
        lightbox.classList.remove('hidden');

        const res = await api.call('git_file_log', { file: path });

        if (!res.success) {
            list.innerHTML = `<p class="git-history-empty">${t('git.failed')}</p>`;
            return;
        }
        if (!res.data.length) {
            list.innerHTML = `<p class="git-history-empty">${t('git.no-commits')}</p>`;
            return;
        }

        list.innerHTML = '';
        res.data.forEach((commit, i) => {
            const row = document.createElement('div');
            row.className = 'git-commit-row';

            const meta = document.createElement('div');
            meta.className = 'git-commit-meta';
            meta.innerHTML =
                `<span class="git-commit-author">${escHtml(commit.author)}</span>` +
                `<span class="git-commit-time">${formatRelTime(commit.timestamp)}</span>` +
                (i === 0 ? `<span class="git-commit-badge">${t('git.current')}</span>` : '') +
                `<span class="git-commit-hash">${escHtml(commit.short_hash)}</span>`;

            const msg = document.createElement('div');
            msg.className = 'git-commit-message';
            msg.textContent = commit.message || '';

            row.appendChild(meta);
            row.appendChild(msg);

            // Action row: Diff always; Restore only for non-current
            const actions = document.createElement('div');
            actions.className = 'git-commit-row-actions';

            const diffBtn = document.createElement('button');
            diffBtn.className = 'btn btn-sm btn-secondary git-diff-btn';
            diffBtn.textContent = t('git.diff-btn');
            diffBtn.addEventListener('click', async () => {
                diffMeta.textContent = `${commit.short_hash} · ${commit.message || ''}`;
                document.getElementById('git-diff-content').textContent = t('git.diff-loading');
                showDiff();
                const r = await api.call('git_file_diff', { file: path, hash: commit.hash });
                if (!r.success) {
                    document.getElementById('git-diff-content').textContent = t('git.diff-failed');
                    return;
                }
                if (!r.diff || !r.diff.trim()) {
                    document.getElementById('git-diff-content').textContent = t('git.diff-empty');
                    return;
                }
                renderDiff(r.diff);
            });
            actions.appendChild(diffBtn);

            if (i > 0) {
                const restoreBtn = document.createElement('button');
                restoreBtn.className = 'btn btn-sm btn-secondary git-restore-btn';
                restoreBtn.textContent = t('git.restore-btn');
                restoreBtn.addEventListener('click', async () => {
                    const date = new Date(commit.timestamp * 1000).toLocaleString();
                    const confirmed = await confirmModal(
                        t('git.restore-confirm', { date, author: commit.author }),
                        { confirmLabel: t('git.restore-btn'), dangerous: true, icon: icons.warning }
                    );
                    if (!confirmed) return;
                    restoreBtn.disabled = true;
                    const r = await api.call('git_restore', { file: path, hash: commit.hash }, 'POST');
                    restoreBtn.disabled = false;
                    if (r.success) {
                        showToast(t('git.restore-done'), 'success');
                        close();
                        const { loadPage } = await import('../page_view/index.js');
                        loadPage(state.currentPagePath, state.currentPageId, state.currentPageTags);
                    } else {
                        showToast(r.message || t('git.restore-failed'), 'error');
                    }
                });
                actions.appendChild(restoreBtn);
            }

            row.appendChild(actions);
            list.appendChild(row);
        });
    });

    // Git tracking toggle button
    const toggleBtn = document.getElementById('git-commit-toggle-btn');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', async () => {
            const path = state.currentPagePath;
            if (!path) return;
            const newVal = !state.currentFileGitCommit;
            const res = await api.call('set_git_commit', { file: path, enabled: newVal ? '1' : '0' }, 'POST');
            if (res.success) {
                updateGitButtons(newVal);
                // Sync chat state so topic-lightbox checkbox stays in sync
                if (state.currentChatData) state.currentChatData.git_commit = newVal;
                if (state.currentListData) state.currentListData.git_commit = newVal;
            }
        });
    }

    // Manual snapshot button
    const snapshotBtn = document.getElementById('git-snapshot-btn');
    if (snapshotBtn) {
        snapshotBtn.addEventListener('click', async () => {
            const path = state.currentPagePath;
            if (!path) return;
            snapshotBtn.disabled = true;
            const res = await api.call('commit_snapshot', { file: path }, 'POST');
            snapshotBtn.disabled = false;
            if (res.success) showToast(t('git.snapshot-done'), 'success');
            else showToast(t('git.snapshot-failed'), 'error');
        });
    }
};
