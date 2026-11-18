// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// Space settings — admin only: rename, read-only mode, merge-into-another-space, delete.
//
// Built in JS rather than as markup in index.php because the whole dialog is
// admin-only and every field in it (the space list, the page counts, which spaces
// are frozen) is fetched when it opens. It deliberately uses its own classes, so
// nothing in it is hidden by the read-only CSS it can turn on.
import { api } from '../core/api.js';
import { showToast, confirmModal } from '../core/utils.js';
import { icons } from '../core/icons.js';
import { t } from '../i18n/index.js';
import { invalidateSkills } from '../core/skills.js';

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
 *   onDeleted(spaceName, nextName)     after the space was moved out of the wiki
 *   onReadOnly(spaceName, readonly)    after the flag was flipped
 *   onFmEdit(spaceName, mode)           after the metadata mode changed
 *   onFmStamp(spaceName, mode)          after automatic stamping was switched
 *   onFmTags(spaceName, mode)           after front-matter tag sync was switched
 *   onCopied(names, settings)           after this space's settings were copied onto others
 *   onDefaults(defaults)                after this space's settings became the new-Space defaults
 */
export const openSpaceSettings = async (spaceName, { onRenamed, onMerged, onDeleted, onReadOnly, onFmEdit, onFmStamp, onFmTags, onCopied, onDefaults } = {}) => {
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
        refreshDelete();
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
        refreshShare();
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
        refreshShare();
        showToast(t('spaces.settings.fm-saved'), 'success');
        onFmStamp?.(spaceName, want);
    });

    // ── Tags in front matter ──────────────────────────────────────────────────
    //
    // A third independent switch beside the two above. Off by default for the same reason
    // they are: it makes the wiki write into a page's own block. The hint says the two
    // things people would otherwise discover by surprise — it only applies from now on,
    // and `tags:` stops being editable in the Metadata panel.
    const ftSec = section(t('spaces.settings.fmtags-title'), t('spaces.settings.fmtags-hint'));
    const ftRow = el('div', 'space-settings-row');
    const ftSel = el('select', 'form-control');
    ftSel.id = 'space-fm-tags';
    [['off', 'spaces.settings.fmtags-off'], ['on', 'spaces.settings.fmtags-on']]
        .forEach(([value, key]) => {
            const o = el('option', '', t(key));
            o.value = value;
            ftSel.appendChild(o);
        });
    ftSel.value = self.fm_tags_sync || 'off';
    ftRow.appendChild(ftSel);
    ftSec.appendChild(ftRow);
    ftSec.appendChild(el('p', 'pref-hint', t('spaces.settings.fmtags-warn')));
    panes.content.appendChild(ftSec);

    ftSel.addEventListener('change', async () => {
        const want = ftSel.value;
        ftSel.disabled = true;
        const res = await api.call('admin_set_space_fm_tags_sync',
            { space_name: spaceName, mode: want }, 'POST');
        ftSel.disabled = false;
        if (!res.success) {
            ftSel.value = self.fm_tags_sync || 'off';         // the server is the truth
            showToast(res.message || t('spaces.settings.fm-failed'), 'error');
            return;
        }
        self.fm_tags_sync = want;
        refreshShare();
        showToast(t('spaces.settings.fm-saved'), 'success');
        onFmTags?.(spaceName, want);
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
        refreshShare();
        showToast(t('spaces.settings.fm-saved'), 'success');
    });

    // ── AI skills ─────────────────────────────────────────────────────────────
    //
    // The Space's half of the switch (the AI User has the other), covering both this
    // space's own skills/ folder and the wiki-wide skills. Editing them lives in
    // Admin → Skills, which the hint names: one editor for both stores beats a second one
    // squeezed into this dialog.
    const skSec = section(t('spaces.settings.skills-title'), t('spaces.settings.skills-hint'));
    const skRow = el('label', 'space-settings-switch-row');
    const skBox = el('input', 'space-settings-switch');
    skBox.type = 'checkbox';
    skBox.checked = !!self.skills;
    skRow.appendChild(skBox);
    skRow.appendChild(el('span', '', t('spaces.settings.skills-label')));
    skSec.appendChild(skRow);
    skSec.appendChild(el('p', 'pref-hint', self.skill_count
        ? t('spaces.settings.skills-count', { n: self.skill_count })
        : t('spaces.settings.skills-empty')));
    panes.ai.appendChild(skSec);

    skBox.addEventListener('change', async () => {
        const want = skBox.checked;
        skBox.disabled = true;
        const res = await api.call('admin_set_space_skills',
            { space_name: spaceName, skills: want ? '1' : '0' }, 'POST');
        skBox.disabled = false;
        if (!res.success) {
            skBox.checked = !want;                       // the server is the truth
            showToast(res.message || t('spaces.settings.skills-failed'), 'error');
            return;
        }
        self.skills = want;
        refreshShare();
        invalidateSkills();
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

    // ── Use these settings elsewhere ──────────────────────────────────────────
    //
    // The Content and AI settings of this Space, copied onto others or made what new
    // Spaces start with. Both *copy*: nothing inherits, so changing the defaults later
    // never reaches a Space that already exists. Read-only is never part of either —
    // freezing every Space must not happen as a side effect of tidying their policy.
    // First in the pane, above merge and delete: the two destructive actions stay
    // together at the bottom.
    const shareSec = section(t('spaces.settings.share-title'), t('spaces.settings.share-hint'));
    const shareRow = el('div', 'space-settings-row');
    const copyBtn  = el('button', 'btn btn-secondary', t('spaces.settings.copy-btn'));
    copyBtn.id = 'space-copy-btn';
    const defBtn   = el('button', 'btn btn-secondary', t('spaces.settings.defaults-btn'));
    defBtn.id = 'space-defaults-btn';
    shareRow.appendChild(copyBtn);
    shareRow.appendChild(defBtn);
    shareSec.appendChild(shareRow);
    const shareStatus = el('div', 'space-settings-status');
    shareStatus.id = 'space-defaults-status';
    shareSec.appendChild(shareStatus);
    panes.advanced.appendChild(shareSec);

    let defaults = info.space_defaults || {};
    const POLICY_KEYS = ['fm_edit', 'fm_autostamp', 'fm_tags_sync', 'memory', 'skills'];
    const policyOf = (s) => ({
        fm_edit: s.fm_edit || 'off', fm_autostamp: s.fm_autostamp || 'off',
        fm_tags_sync: s.fm_tags_sync || 'off', memory: !!s.memory, skills: !!s.skills,
    });
    // How each setting reads in a summary: the section title plus the wording its own
    // control uses, so the preview says what the Content and AI tabs say.
    const settingLabel = (k) => t({
        fm_edit: 'spaces.settings.fm-title', fm_autostamp: 'spaces.settings.stamp-title',
        fm_tags_sync: 'spaces.settings.fmtags-title', memory: 'spaces.settings.memory-title',
        skills: 'spaces.settings.skills-title',
    }[k]);
    const valueLabel = (k, v) => {
        if (k === 'fm_edit') return t(v === 'manual' ? 'spaces.settings.fm-manual' : 'spaces.settings.fm-off');
        return t(v === true || v === 'on' ? 'spaces.settings.value-on' : 'spaces.settings.value-off');
    };
    const others = spaces.filter(s => s.name !== spaceName);

    function refreshShare() {
        const mine = policyOf(self);
        const diff = POLICY_KEYS.filter(k => mine[k] !== policyOf(defaults)[k]);
        shareStatus.textContent = diff.length
            ? t('spaces.settings.defaults-differ', {
                list: diff.map(k => `${settingLabel(k)}: ${valueLabel(k, policyOf(defaults)[k])}`).join(' · ') })
            : t('spaces.settings.defaults-same');
        defBtn.disabled = !diff.length;
        copyBtn.disabled = !others.length;
        copyBtn.title = others.length ? '' : t('spaces.settings.merge-no-target');
    }
    refreshShare();

    defBtn.addEventListener('click', async () => {
        defBtn.disabled = true;
        const res = await api.call('admin_space_defaults', { from_space: spaceName }, 'POST');
        if (!res.success) {
            showToast(res.message || t('spaces.settings.fm-failed'), 'error');
            refreshShare();
            return;
        }
        defaults = res.defaults || defaults;
        refreshShare();
        showToast(t('spaces.settings.defaults-saved'), 'success');
        onDefaults?.(defaults);
    });

    copyBtn.addEventListener('click', () => openCopyDialog());

    async function openCopyDialog() {
        const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const html = `<div class="space-copy-view">
            <p class="space-settings-hint">${esc(t('spaces.settings.copy-intro', { name: spaceName }))}</p>
            <div class="form-label">${esc(t('spaces.settings.copy-what'))}</div>
            <label class="space-settings-switch-row"><input type="checkbox" class="space-copy-group" value="content" checked>
                <span>${esc(t('spaces.settings.copy-group-content'))}</span></label>
            <label class="space-settings-switch-row"><input type="checkbox" class="space-copy-group" value="ai" checked>
                <span>${esc(t('spaces.settings.copy-group-ai'))}</span></label>
            <div class="form-label space-copy-label">${esc(t('spaces.settings.copy-to'))}</div>
            <div class="space-copy-targets">${others.map(s => `
                <label class="space-settings-switch-row"><input type="checkbox" class="space-copy-target" value="${esc(s.name)}" checked>
                    <span>${esc(s.name)}</span></label>`).join('')}</div>
            <div class="form-label space-copy-label">${esc(t('spaces.settings.copy-preview'))}</div>
            <div id="space-copy-preview" class="space-settings-status"></div>
        </div>`;
        const pending = confirmModal(t('spaces.settings.copy-title', { name: spaceName }), {
            messageHtml: html,
            confirmLabel: t('spaces.settings.copy-apply'),
            icon: icons.space,
        });
        const okBtn   = document.getElementById('confirm-modal-ok');
        const preview = document.getElementById('space-copy-preview');
        const view    = preview.closest('.space-copy-view');
        const picked  = (cls) => [...view.querySelectorAll(`.${cls}:checked`)].map(b => b.value);
        let changedCount = 0;
        let seq = 0;

        // Every change re-asks the server for the plan (dry run), so what is previewed is
        // what the same code will then write. The confirm button only arms on a plan
        // that changes something; `seq` drops an answer overtaken by a later click.
        const refresh = async () => {
            const groups = picked('space-copy-group');
            const targets = picked('space-copy-target');
            okBtn.disabled = true;
            changedCount = 0;
            // Taken before the early return too: an answer still in flight from the
            // previous click must not repaint (and re-arm) a dialog with nothing chosen.
            const mine = ++seq;
            if (!groups.length || !targets.length) {
                preview.textContent = t(groups.length ? 'spaces.settings.copy-pick-space' : 'spaces.settings.copy-pick-group');
                return;
            }
            preview.textContent = t('spaces.settings.merge-checking');
            const res = await api.call('admin_space_copy_settings',
                { source: spaceName, targets: targets.join(','), groups: groups.join(','), dry_run: '1' }, 'POST');
            if (mine !== seq) return;
            if (!res.success) {
                preview.textContent = res.message || t('spaces.settings.fm-failed');
                return;
            }
            preview.textContent = '';
            const list = el('ul', 'space-settings-renames space-copy-plan');
            (res.plan || []).forEach(row => {
                const keys = Object.keys(row.changes || {});
                const li = el('li', '');
                li.appendChild(el('strong', '', row.space + ': '));
                li.appendChild(document.createTextNode(keys.length
                    ? keys.map(k => `${settingLabel(k)} ${valueLabel(k, row.changes[k][0])} → ${valueLabel(k, row.changes[k][1])}`).join(' · ')
                    : t('spaces.settings.copy-unchanged')));
                if (keys.length) changedCount++;
                list.appendChild(li);
            });
            preview.appendChild(list);
            okBtn.textContent = t('spaces.settings.copy-apply-n', { n: changedCount });
            okBtn.disabled = !changedCount;
        };
        view.addEventListener('change', refresh);
        refresh();

        const ok = await pending;
        // The confirm dialog is shared: hand its button back as the next caller expects.
        okBtn.disabled = false;
        if (!ok || !changedCount) return;
        const groups = picked('space-copy-group');
        const targets = picked('space-copy-target');
        const res = await api.call('admin_space_copy_settings',
            { source: spaceName, targets: targets.join(','), groups: groups.join(',') }, 'POST');
        if (!res.success) {
            showToast(res.message || t('spaces.settings.copy-failed'), 'error');
            return;
        }
        const changed = res.changed || [];
        // The copies' settings are now this Space's, as far as the dialog's cached list
        // knows — the merge list and a reopened dialog read from it.
        const copied = {};
        groups.forEach(g => (g === 'content' ? ['fm_edit', 'fm_autostamp', 'fm_tags_sync'] : ['memory', 'skills'])
            .forEach(k => { copied[k] = self[k]; }));
        spaces.filter(s => changed.includes(s.name)).forEach(s => Object.assign(s, copied));
        showToast(t('spaces.settings.copied', { n: changed.length }), 'success');
        if (groups.includes('ai')) invalidateSkills();
        onCopied?.(changed, copied);
    }

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

    // ── Delete ────────────────────────────────────────────────────────────────
    //
    // A soft delete — the server moves the folder to WIKI_SYSTEM_DATA/deleted_spaces/ —
    // but for everyone using the wiki it is gone, so the confirmation asks for the
    // space's name rather than a click. The server compares the name as well.
    const delSec = section(t('spaces.settings.delete-title'), t('spaces.settings.delete-hint'));
    const delRow = el('div', 'space-settings-row');
    const delBtn = el('button', 'btn btn-danger', t('spaces.settings.delete-btn'));
    delRow.appendChild(delBtn);
    delSec.appendChild(delRow);
    const delStatus = el('div', 'space-settings-status');
    delSec.appendChild(delStatus);
    panes.advanced.appendChild(delSec);

    const deleteBlockedText = (code) => ({
        'last-space':       t('spaces.settings.blocked-last-space'),
        'source-readonly':  t('spaces.settings.blocked-delete-readonly'),
        'confirm-mismatch': t('spaces.settings.blocked-confirm-mismatch'),
    }[code] || blockedText(code));

    // The same two refusals the server makes, said before the button is pressed.
    function refreshDelete() {
        const code = spaces.length <= 1 ? 'last-space' : (self.readonly ? 'source-readonly' : '');
        delBtn.disabled = !!code;
        delStatus.className = 'space-settings-status' + (code ? ' is-blocked' : '');
        delStatus.textContent = code ? deleteBlockedText(code) : '';
    }
    refreshDelete();

    delBtn.addEventListener('click', async () => {
        const esc = s => s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const html = `<p>${esc(t('spaces.settings.delete-confirm-body', { name: spaceName, pages: self.pages ?? 0 }))}</p>
            <div class="form-group">
                <label class="form-label" for="space-delete-confirm">${esc(t('spaces.settings.delete-confirm-label', { name: spaceName }))}</label>
                <input type="text" id="space-delete-confirm" class="form-control" autocomplete="off" spellcheck="false">
            </div>`;
        const pending = confirmModal(t('spaces.settings.delete-confirm-title', { name: spaceName }), {
            messageHtml: html,
            confirmLabel: t('spaces.settings.delete-confirm-btn'),
            dangerous: true,
            icon: icons.warning,
        });
        // confirmModal has no notion of a precondition, so the button is held here: it
        // only arms once the typed name matches exactly. The dialog is shared, hence the
        // reset afterwards whichever way it closed.
        const okBtn = document.getElementById('confirm-modal-ok');
        const input = document.getElementById('space-delete-confirm');
        okBtn.disabled = true;
        input?.addEventListener('input', () => { okBtn.disabled = input.value.trim() !== spaceName; });
        setTimeout(() => input?.focus(), 60);
        const ok = await pending;
        const typed = input?.value.trim() || '';
        okBtn.disabled = false;
        if (!ok || typed !== spaceName) return;

        delBtn.disabled = true;
        delStatus.className = 'space-settings-status';
        delStatus.textContent = t('spaces.settings.delete-running');
        const res = await api.call('admin_delete_space', { name: spaceName, confirm: typed }, 'POST');
        delStatus.className = 'space-settings-status is-blocked';
        if (!res.success) {
            delBtn.disabled = false;
            delStatus.textContent = res.blocked
                ? deleteBlockedText(res.blocked)
                : (res.message || t('spaces.settings.delete-failed'));
            return;
        }
        showToast(t('spaces.settings.deleted', { name: spaceName }), 'success');
        close();
        onDeleted?.(spaceName, res.next || null);
    });

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
