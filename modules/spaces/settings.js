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
 *   onFmEdit(spaceName, mode)           after the metadata mode changed
 *   onFmStamp(spaceName, mode)          after automatic stamping was switched
 */
export const openSpaceSettings = async (spaceName, { onRenamed, onMerged, onReadOnly, onFmEdit, onFmStamp } = {}) => {
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

    // ── Tabs ──────────────────────────────────────────────────────────────────
    //
    // Five sections had already pushed this dialog past its box once (see the
    // `.space-settings-content` note in CLAUDE.md); memory is the sixth. Grouping beats
    // growing it again — a taller box runs out of viewport, and scrolling past four
    // settings to reach the fifth is how the Close button ended up off-screen before.
    //
    // The bar reuses the admin panel's `.admin-tab-bar` / `.admin-tab`, rather than a
    // second tab system that would have to be kept looking like the first.
    //
    // **Every pane stays in the DOM**, hidden rather than removed. Freezing a space
    // disables merging it, and that coupling now crosses a tab boundary — with the nodes
    // always present, `refreshMerge()` keeps working exactly as it did when the two
    // sections were neighbours.
    const tabBar = el('div', 'admin-tab-bar');
    body.appendChild(tabBar);
    const panes = {};
    const pane = (name, labelKey, isFirst = false) => {
        const btn = el('button', 'admin-tab' + (isFirst ? ' active' : ''), t(labelKey));
        btn.dataset.tab = name;
        tabBar.appendChild(btn);
        const box = el('div', 'space-settings-pane' + (isFirst ? '' : ' hidden'));
        body.appendChild(box);
        panes[name] = box;
        btn.addEventListener('click', () => {
            tabBar.querySelectorAll('.admin-tab').forEach(b => b.classList.toggle('active', b === btn));
            Object.entries(panes).forEach(([k, v]) => v.classList.toggle('hidden', k !== name));
        });
        return box;
    };
    pane('general',  'spaces.settings.tab-general', true);
    pane('content',  'spaces.settings.tab-content');
    pane('ai',       'spaces.settings.tab-ai');
    pane('advanced', 'spaces.settings.tab-advanced');


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
    panes.general.appendChild(renameSec);

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
    panes.general.appendChild(roSec);

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
        // A frozen space cannot be merged into or away, so the merge section follows —
        // and neither metadata editing nor remembering does anything while it is frozen,
        // which the other two panes say for themselves. All of them are in the DOM
        // whichever tab is open, so this reaches them without switching tabs.
        refreshMerge();
        refreshFrozen();
        onReadOnly?.(spaceName, want);
    });

    // ── Page metadata (front matter) ──────────────────────────────────────────
    //
    // Per Space, because the granularity is the point: one Space can mirror an Obsidian
    // vault the wiki should keep its hands off, while another wants its metadata editable.
    const fmSec = section(t('spaces.settings.fm-title'), t('spaces.settings.fm-hint'));
    const fmRow = el('div', 'space-settings-row');
    const fmSel = el('select', 'form-control');
    fmSel.id = 'space-fm-edit';
    [['off', 'spaces.settings.fm-off'], ['manual', 'spaces.settings.fm-manual']]
        .forEach(([value, key]) => {
            const o = el('option', '', t(key));
            o.value = value;
            fmSel.appendChild(o);
        });
    fmSel.value = self.fm_edit || 'off';
    fmRow.appendChild(fmSel);
    fmSec.appendChild(fmRow);
    panes.content.appendChild(fmSec);

    fmSel.addEventListener('change', async () => {
        const want = fmSel.value;
        fmSel.disabled = true;
        const res = await api.call('admin_set_space_fm_edit',
            { space_name: spaceName, mode: want }, 'POST');
        fmSel.disabled = false;
        if (!res.success) {
            fmSel.value = self.fm_edit || 'off';         // the server is the truth
            showToast(res.message || t('spaces.settings.fm-failed'), 'error');
            return;
        }
        self.fm_edit = want;
        showToast(t('spaces.settings.fm-saved'), 'success');
        onFmEdit?.(spaceName, want);
    });

    // ── Automatic stamping ────────────────────────────────────────────────────
    //
    // Independent of the setting above, not a third mode of it: maintaining the timestamps
    // while still editing your own fields by hand is the combination people want.
    const stampSec = section(t('spaces.settings.stamp-title'), t('spaces.settings.stamp-hint'));
    const stampRow = el('div', 'space-settings-row');
    const stampSel = el('select', 'form-control');
    stampSel.id = 'space-fm-stamp';
    [['off', 'spaces.settings.stamp-off'], ['on', 'spaces.settings.stamp-on']]
        .forEach(([value, key]) => {
            const o = el('option', '', t(key));
            o.value = value;
            stampSel.appendChild(o);
        });
    stampSel.value = self.fm_autostamp || 'off';
    stampRow.appendChild(stampSel);
    stampSec.appendChild(stampRow);
    // The consequence spelled out, because it is the one surprise in the feature: those
    // four fields stop being the author's, and a hand-edited value is overwritten.
    stampSec.appendChild(el('p', 'pref-hint', t('spaces.settings.stamp-warn')));
    panes.content.appendChild(stampSec);

    stampSel.addEventListener('change', async () => {
        const want = stampSel.value;
        stampSel.disabled = true;
        const res = await api.call('admin_set_space_fm_autostamp',
            { space_name: spaceName, mode: want }, 'POST');
        stampSel.disabled = false;
        if (!res.success) {
            stampSel.value = self.fm_autostamp || 'off';     // the server is the truth
            showToast(res.message || t('spaces.settings.fm-failed'), 'error');
            return;
        }
        self.fm_autostamp = want;
        showToast(t('spaces.settings.fm-saved'), 'success');
        onFmStamp?.(spaceName, want);
    });

    // ── AI memory ─────────────────────────────────────────────────────────────
    //
    // Per space because the *store* is per space: memories are pages in `memory/` inside
    // this space, which is what keeps them inside the isolation every other read already
    // obeys. One shared memory space would be a channel between Spaces.
    //
    // This is half the switch — an AI User also has to have learning on. The hint says
    // so, because "memory is off" with two places to look is otherwise a support call.
    const memSec = section(t('spaces.settings.memory-title'), t('spaces.settings.memory-hint'));
    const memRow = el('label', 'space-settings-switch-row');
    const memBox = el('input', 'space-settings-switch');
    memBox.type = 'checkbox';
    memBox.checked = !!self.memory;
    memRow.appendChild(memBox);
    memRow.appendChild(el('span', '', t('spaces.settings.memory-label')));
    memSec.appendChild(memRow);
    // What is actually in there, so turning it off is an informed decision rather than a
    // guess about whether anything would be stranded.
    const memCount = el('p', 'pref-hint', self.memories
        ? t('spaces.settings.memory-count', { n: self.memories })
        : t('spaces.settings.memory-empty'));
    memSec.appendChild(memCount);
    panes.ai.appendChild(memSec);

    memBox.addEventListener('change', async () => {
        const want = memBox.checked;
        memBox.disabled = true;
        const res = await api.call('admin_set_space_memory',
            { space_name: spaceName, memory: want ? '1' : '0' }, 'POST');
        memBox.disabled = false;
        if (!res.success) {
            memBox.checked = !want;                      // the server is the truth
            showToast(res.message || t('spaces.settings.memory-failed'), 'error');
            return;
        }
        self.memory = want;
        showToast(t('spaces.settings.fm-saved'), 'success');
    });

    // Freezing a space already stops both of these at the server — metadata editing
    // through `set_frontmatter` being an `$edit_action`, and remembering through
    // WIKI_AI_WRITE_TOOLS. What it does not do is *say so*: the settings stay on, so the
    // dialog reads as though they still apply. The Advanced tab has always told the truth
    // here ("This space is read-only. Turn that off before merging it away."), and these
    // two now do the same — and sit *below* the settings they are about, where the
    // merge status already sits, rather than above them where a pane opens with a
    // caveat before it has said what the caveat is about.
    //
    // A note rather than a disabled control. These are policy, not content, and setting
    // the policy of a space you intend to unfreeze later is a real thing to want —
    // `create_space` / `rename_space` are exempt from the freeze for the same reason.
    // Disabling them would take away a capability in order to restate a rule the server
    // already enforces.
    const frozenNotes = [];
    const frozenNote = (paneName, key) => {
        const el_ = el('p', 'space-settings-status is-blocked', t(key));
        panes[paneName].appendChild(el_);
        frozenNotes.push(el_);
        return el_;
    };
    const refreshFrozen = () => {
        frozenNotes.forEach(n => n.classList.toggle('hidden', !self.readonly));
    };
    frozenNote('content', 'spaces.settings.frozen-fm');
    frozenNote('ai',      'spaces.settings.frozen-memory');
    refreshFrozen();   // they are built shown, so a space that is not frozen hides them now

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
    panes.advanced.appendChild(mergeSec);

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
