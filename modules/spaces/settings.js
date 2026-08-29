// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// Space settings — admin only: rename, read-only mode, and merge-into-another-space.
//
// Built in JS rather than as markup in index.php because the whole dialog is
// admin-only and every field in it (the space list, the page counts, which spaces
// are frozen) is fetched when it opens. It deliberately uses its own classes, so
// nothing in it is hidden by the read-only CSS it can turn on.
import { api } from '../core/api.js';
import { showToast, confirmModal } from '../core/utils.js';
import { icons } from '../core/icons.js';
import { t } from '../i18n/index.js';

const OVERLAY_ID = 'space-settings-modal';

const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
};

const section = (title, hint) => {
    const wrap = el('div', 'space-settings-section');
    wrap.appendChild(el('h4', 'space-settings-title', title));
    if (hint) wrap.appendChild(el('p', 'space-settings-hint', hint));
    return wrap;
};

/**
 * @param {string} spaceName            the space the dialog acts on (the active one)
 * @param {object} handlers
 *   onRenamed(oldName, newName)        after a successful rename
 *   onMerged(sourceName, targetName)   after the source space has been dissolved
 *   onReadOnly(spaceName, readonly)    after the flag was flipped
 */
export const openSpaceSettings = async (spaceName, { onRenamed, onMerged, onReadOnly } = {}) => {
    document.getElementById(OVERLAY_ID)?.remove();

    const info = await api.call('admin_space_settings');
    if (!info.success) {
        showToast(info.message || t('spaces.settings.load-failed'), 'error');
        return;
    }
    const spaces = info.data || [];
    const self   = spaces.find(s => s.name === spaceName);
    if (!self) {
        showToast(t('spaces.settings.load-failed'), 'error');
        return;
    }

    const overlay = el('div', 'lightbox-overlay');
    overlay.id = OVERLAY_ID;
    const content = el('div', 'lightbox-content space-settings-content');

    const header = el('div', 'input-modal-header');
    const hIcon  = el('span', 'input-modal-icon');
    hIcon.innerHTML = icons.space;
    header.appendChild(hIcon);
    header.appendChild(el('h3', '', t('spaces.settings.title', { name: spaceName })));
    content.appendChild(header);

    const body = el('div', 'space-settings-body');
    content.appendChild(body);

    // ── Rename ────────────────────────────────────────────────────────────────
    const renameSec = section(t('spaces.settings.rename-title'), t('spaces.settings.rename-hint'));
    const renameRow = el('div', 'space-settings-row');
    const renameInput = el('input', 'form-control');
    renameInput.type  = 'text';
    renameInput.value = spaceName;
    const renameBtn = el('button', 'btn btn-blue', t('spaces.settings.rename-btn'));
    renameRow.appendChild(renameInput);
    renameRow.appendChild(renameBtn);
    renameSec.appendChild(renameRow);
    body.appendChild(renameSec);

    renameBtn.addEventListener('click', async () => {
        const next = renameInput.value.trim();
        if (!next || next === spaceName) return;
        if (!await confirmModal(t('spaces.rename-warn'), {
            confirmLabel: t('spaces.rename-confirm-btn'),
            icon: icons.space,
        })) return;
        const res = await api.call('rename_space', { old_name: spaceName, new_name: next }, 'POST');
        if (!res.success) {
            showToast(res.message || t('spaces.rename-failed'), 'error');
            return;
        }
        showToast(t('spaces.renamed', { name: next }), 'success');
        close();
        onRenamed?.(spaceName, next);
    });

    // ── Read-only ─────────────────────────────────────────────────────────────
    const roSec = section(t('spaces.settings.readonly-title'), t('spaces.settings.readonly-hint'));
    const roRow = el('label', 'space-settings-switch-row');
    const roBox = el('input', 'space-settings-switch');
    roBox.type = 'checkbox';
    roBox.checked = !!self.readonly;
    roRow.appendChild(roBox);
    roRow.appendChild(el('span', '', t('spaces.settings.readonly-label')));
    roSec.appendChild(roRow);
    body.appendChild(roSec);

    roBox.addEventListener('change', async () => {
        const want = roBox.checked;
        roBox.disabled = true;
        const res = await api.call('admin_set_space_readonly',
            { space_name: spaceName, readonly: want ? '1' : '0' }, 'POST');
        roBox.disabled = false;
        if (!res.success) {
            roBox.checked = !want;                       // the server is the truth
            showToast(res.message || t('spaces.settings.readonly-failed'), 'error');
            return;
        }
        self.readonly = want;
        showToast(want ? t('spaces.settings.readonly-on', { name: spaceName })
                       : t('spaces.settings.readonly-off', { name: spaceName }), 'success');
        // A frozen space cannot be merged into or away, so the merge section follows.
        refreshMerge();
        onReadOnly?.(spaceName, want);
    });

    // ── Merge ─────────────────────────────────────────────────────────────────
    const mergeSec = section(t('spaces.settings.merge-title'), t('spaces.settings.merge-hint'));
    const mergeRow = el('div', 'space-settings-row');
    const targetSel = el('select', 'form-control');
    const mergeBtn  = el('button', 'btn btn-danger', t('spaces.settings.merge-btn'));
    mergeRow.appendChild(targetSel);
    mergeRow.appendChild(mergeBtn);
    mergeSec.appendChild(mergeRow);
    const mergeStatus = el('div', 'space-settings-status');
    mergeSec.appendChild(mergeStatus);
    body.appendChild(mergeSec);

    const candidates = spaces.filter(s => s.name !== spaceName);
    if (!candidates.length) {
        targetSel.appendChild(el('option', '', t('spaces.settings.merge-no-target')));
    }
    candidates.forEach(s => {
        const o = el('option', '', s.readonly ? `${s.name} — ${t('spaces.settings.readonly-label')}` : s.name);
        o.value = s.name;
        targetSel.appendChild(o);
    });

    // Blocking reasons come back as codes, so the dialog can phrase each one properly
    // instead of echoing a server sentence that no locale could translate.
    const blockedText = (code) => ({
        'source-git':      t('spaces.settings.blocked-source-git', { name: spaceName }),
        'source-readonly': t('spaces.settings.blocked-source-readonly'),
        'target-readonly': t('spaces.settings.blocked-target-readonly'),
        'no-source':       t('spaces.settings.blocked-missing'),
        'no-target':       t('spaces.settings.blocked-missing'),
        'same':            t('spaces.settings.blocked-same'),
        'invalid':         t('spaces.settings.blocked-missing'),
    }[code] || t('spaces.settings.blocked-missing'));

    let plan = null;

    const refreshMerge = async () => {
        plan = null;
        mergeBtn.disabled = true;
        const target = targetSel.value;
        if (!target) { mergeStatus.textContent = ''; return; }
        mergeStatus.className = 'space-settings-status';
        mergeStatus.textContent = t('spaces.settings.merge-checking');
        const res = await api.call('admin_merge_space_preflight', { source: spaceName, target });
        if (!res.success) {
            mergeStatus.className = 'space-settings-status is-blocked';
            mergeStatus.textContent = res.message || t('spaces.settings.load-failed');
            return;
        }
        if (res.blocked) {
            mergeStatus.className = 'space-settings-status is-blocked';
            mergeStatus.textContent = blockedText(res.blocked);
            return;
        }
        plan = res;
        mergeBtn.disabled = false;
        mergeStatus.className = 'space-settings-status';
        mergeStatus.textContent = t('spaces.settings.merge-summary', {
            pages: res.pages, target, renamed: res.renamed, skipped: res.skipped,
        });
        if (res.renames?.length) {
            const list = el('ul', 'space-settings-renames');
            res.renames.slice(0, 8).forEach(r => list.appendChild(el('li', '', `${r.from} → ${r.to}`)));
            if (res.renamed > 8) list.appendChild(el('li', '', t('spaces.settings.merge-more', { n: res.renamed - 8 })));
            mergeStatus.appendChild(list);
        }
    };

    targetSel.addEventListener('change', refreshMerge);

    mergeBtn.addEventListener('click', async () => {
        const target = targetSel.value;
        if (!target || !plan) return;
        const ok = await confirmModal(t('spaces.settings.merge-confirm-title', { source: spaceName, target }), {
            message: t('spaces.settings.merge-confirm-body', {
                source: spaceName, target, pages: plan.pages, renamed: plan.renamed, skipped: plan.skipped,
            }),
            confirmLabel: t('spaces.settings.merge-confirm-btn'),
            dangerous: true,
            icon: icons.warning,
        });
        if (!ok) return;
        mergeBtn.disabled = true;
        mergeStatus.className = 'space-settings-status';
        mergeStatus.textContent = t('spaces.settings.merge-running');
        const res = await api.call('admin_merge_space', { source: spaceName, target }, 'POST');
        if (!res.success) {
            mergeBtn.disabled = false;
            mergeStatus.className = 'space-settings-status is-blocked';
            // A part-way failure is reported as-is: some files have already moved and
            // the admin needs to know which state the wiki is actually in.
            mergeStatus.textContent = res.blocked
                ? blockedText(res.blocked)
                : (res.message || t('spaces.settings.merge-failed'));
            if (res.leftovers?.length) {
                const list = el('ul', 'space-settings-renames');
                res.leftovers.forEach(p => list.appendChild(el('li', '', p)));
                mergeStatus.appendChild(list);
            }
            return;
        }
        showToast(t('spaces.settings.merged', { source: spaceName, target, moved: res.moved }), 'success');
        close();
        onMerged?.(spaceName, target);
    });

    refreshMerge();

    // ── Footer ────────────────────────────────────────────────────────────────
    const footer = el('div', 'lightbox-footer');
    const closeBtn = el('button', 'btn btn-secondary', t('btn.close'));
    footer.appendChild(closeBtn);
    content.appendChild(footer);

    overlay.appendChild(content);
    document.body.appendChild(overlay);

    function close() {
        document.removeEventListener('keydown', onKey);
        overlay.remove();
    }
    const onKey = (e) => { if (e.key === 'Escape') close(); };
    closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    setTimeout(() => document.addEventListener('keydown', onKey), 150);
};
