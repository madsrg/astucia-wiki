// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.

// Admin → AI → Skills: one editor for both stores — the wiki-wide skills in
// WIKI_SYSTEM_DATA and each Space's skills/ folder — plus import from a repository or a
// zip. The rules live server-side (skills.php); this lists, edits and imports.
// Like Workflows, the list, the editor and the import each take over the pane in turn
// rather than opening dialogs over it: a SKILL.md wants the room.

import { api } from '../core/api.js';
import { showToast, confirmModal } from '../core/utils.js';
import { t } from '../i18n/index.js';
import { invalidateSkills } from '../core/skills.js';

const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

let data  = { skills: [], spaces: [], default: true, template: '', can_zip: true, can_git: true };
let scope = '';           // '' = wiki-wide, otherwise a Space name

const container = () => document.getElementById('admin-skills-list');
const footer    = (show) => document.getElementById('admin-footer-skills')?.classList.toggle('hidden', !show);

// A validation problem in the reader's language; the server's English is the fallback
// for a code this client does not know yet.
const errText = (e) => {
    const k = `admin.skills.err.${e.code}`;
    const s = t(k);
    return s === k ? e.message : s;
};

const scopeLabel = (s) => s === '' ? t('admin.skills.scope-wiki') : s;

const scopeOptions = (selected, withState = true) => [
    `<option value=""${selected === '' ? ' selected' : ''}>${esc(t('admin.skills.scope-wiki'))}</option>`,
    ...data.spaces.map(sp => `<option value="${esc(sp.name)}"${selected === sp.name ? ' selected' : ''}>${esc(sp.name)}${withState && !sp.skills ? ' — ' + esc(t('admin.skills.space-off')) : ''}</option>`),
].join('');

export const loadSkills = async () => {
    const box = container();
    if (!box) return;
    box.innerHTML = `<p class="admin-loading">${t('admin.users.loading')}</p>`;
    const res = await api.call('admin_skills_list', { scope });
    if (!res.success) {
        // A Space that has gone away since: fall back to the wiki-wide store.
        if (scope !== '') { scope = ''; return loadSkills(); }
        box.innerHTML = `<p class="admin-empty">${t('admin.users.failed')}</p>`;
        return;
    }
    data = res;
    renderList();
};

const statusCell = (s) => {
    if (!s.valid) return `<span class="admin-job-status admin-job-status-err">${t('admin.skills.invalid')}</span>`
        + s.errors.map(e => `<div class="wf-err">${esc(errText(e))}</div>`).join('');
    const bits = [`<span class="admin-job-status admin-job-status-ok">${t('admin.skills.valid')}</span>`];
    if (s.shadowed_in?.length) bits.push(`<div class="wf-sub">${esc(t('admin.skills.shadowed-in', { spaces: s.shadowed_in.join(', ') }))}</div>`);
    if (s.overrides) bits.push(`<div class="wf-sub">${esc(t('admin.skills.overrides'))}</div>`);
    return bits.join('');
};

const renderList = () => {
    const box = container();
    if (!box) return;
    footer(true);
    const sp = data.spaces.find(x => x.name === scope);
    const notes = [`<div class="wf-note">${t('admin.skills.intro')}</div>`];
    if (data.stray?.length) notes.push(`<div class="wf-note wf-note-warn">${esc(t('admin.skills.stray', { files: data.stray.join(', ') }))}</div>`);
    if (sp && !sp.skills) notes.push(`<div class="wf-note wf-note-warn">${esc(t('admin.skills.space-off-note', { space: sp.name }))}</div>`);

    const head = `
        <div class="skills-head">
            <label for="skills-scope">${t('admin.skills.scope')}</label>
            <select id="skills-scope" class="form-control">${scopeOptions(scope)}</select>
            <label class="wf-check skills-default" title="${esc(t('admin.skills.default-hint'))}">
                <input type="checkbox" id="skills-default" ${data.default ? 'checked' : ''}> ${t('admin.skills.default')}
            </label>
        </div>`;

    let table;
    if (!data.skills.length) {
        table = `<p class="admin-empty">${t(scope === '' ? 'admin.skills.none-wiki' : 'admin.skills.none-space')}</p>`;
    } else {
        table = `<table class="admin-table wf-table"><thead><tr>
            <th>${t('admin.skills.col-name')}</th><th>${t('admin.skills.col-desc')}</th>
            <th>${t('admin.skills.col-status')}</th><th></th></tr></thead><tbody>`
            + data.skills.map(s => `<tr data-folder="${esc(s.folder)}">
                <td class="admin-td-name"><code>${esc(s.name)}</code>${s.files ? `<div class="wf-sub">${esc(t('admin.skills.files-n', { n: s.files }))}</div>` : ''}</td>
                <td class="skills-desc">${esc(s.description) || '<span class="wf-sub">—</span>'}</td>
                <td>${statusCell(s)}</td>
                <td class="wf-row-btns">
                    <button class="btn btn-sm btn-secondary sk-edit">${t('admin.wf.edit-btn')}</button>
                    <button class="btn btn-sm btn-danger sk-delete">${t('btn.delete')}</button>
                </td></tr>`).join('')
            + `</tbody></table>`;
    }
    box.innerHTML = head + notes.join('') + table;

    box.querySelector('#skills-scope').addEventListener('change', (e) => { scope = e.target.value; loadSkills(); });
    box.querySelector('#skills-default').addEventListener('change', async (e) => {
        const on = e.target.checked;
        const res = await api.call('admin_ai_skills_settings', { enabled: on ? '1' : '0' }, 'POST');
        if (!res.success) { e.target.checked = !on; showToast(res.message || t('admin.skills.save-fail'), 'error'); return; }
        data.default = res.enabled;
        showToast(t('spaces.settings.fm-saved'), 'success');
    });
    box.querySelectorAll('tbody tr').forEach(tr => {
        const folder = tr.dataset.folder;
        tr.querySelector('.sk-edit').addEventListener('click', () => openEditor(folder));
        tr.querySelector('.sk-delete').addEventListener('click', async () => {
            const ok = await confirmModal(t('admin.skills.delete-title'), {
                message: t('admin.skills.delete-confirm', { name: folder, scope: scopeLabel(scope) }),
                confirmLabel: t('btn.delete'), cancelLabel: t('btn.cancel'), dangerous: true });
            if (!ok) return;
            const res = await api.call('admin_skill_delete', { scope, name: folder }, 'POST');
            if (!res.success) { showToast(res.message || t('admin.skills.save-fail'), 'error'); return; }
            invalidateSkills();
            loadSkills();
        });
    });
};

// ── Editor ──────────────────────────────────────────────────────────────────────

const openEditor = async (folder) => {
    const box = container();
    if (!box) return;
    footer(false);
    let content = data.template, files = [], path = null;
    if (folder) {
        const res = await api.call('admin_skill_get', { scope, name: folder });
        if (!res.success) { showToast(res.message || t('admin.users.failed'), 'error'); return renderList(); }
        content = res.content; files = res.files || []; path = res.path;
    }
    box.innerHTML = `
        <div class="admin-ai-form skills-editor">
            <div class="wf-history-head">
                <strong>${esc(folder ? t('admin.skills.edit-title', { name: folder }) : t('admin.skills.new-title'))}</strong>
                <span class="wf-sub">${esc(scopeLabel(scope))}${path ? ' · ' + esc(path) : ''}</span>
            </div>
            <div class="wf-note">${t('admin.skills.editor-hint')}</div>
            <textarea id="sk-f-content" class="form-control skills-textarea" spellcheck="false"></textarea>
            <div id="sk-f-errors"></div>
            ${files.length ? `<div class="wf-sub skills-files">${esc(t('admin.skills.files'))}: ${files.map(f => `<code>${esc(f)}</code>`).join(' ')}</div>` : ''}
            ${scope !== '' && folder ? `<div class="wf-sub">${esc(t('admin.skills.files-hint-space'))}</div>` : ''}
            <div style="display:flex;gap:0.5rem;margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid var(--border-color)">
                <button id="sk-f-cancel" class="btn btn-secondary">${t('btn.cancel')}</button>
                <button id="sk-f-save" class="btn btn-green" style="margin-left:auto">${t('btn.save')}</button>
            </div>
        </div>`;
    const ta = box.querySelector('#sk-f-content');
    ta.value = content;
    ta.focus();
    box.querySelector('#sk-f-cancel').addEventListener('click', renderList);
    box.querySelector('#sk-f-save').addEventListener('click', async () => {
        const res = await api.call('admin_skill_save', { scope, original: folder || '', content: ta.value }, 'POST');
        const errBox = box.querySelector('#sk-f-errors');
        if (!res.success) {
            if (res.errors?.length) {
                errBox.innerHTML = res.errors.map(e => `<div class="wf-err">${esc(errText(e))}</div>`).join('');
            } else {
                errBox.innerHTML = '';
                showToast(res.message || t('admin.skills.save-fail'), 'error');
            }
            return;
        }
        invalidateSkills();
        showToast(t(res.created ? 'admin.skills.created' : 'admin.skills.saved', { name: res.name }), 'success');
        loadSkills();
    });
};

// ── Import ──────────────────────────────────────────────────────────────────────

const REASONS = ['type', 'link', 'size', 'unsafe'];
const reasonText = (r) => REASONS.includes(r) ? t(`admin.skills.drop.${r}`) : r;

const openImport = () => {
    const box = container();
    if (!box) return;
    footer(false);
    box.innerHTML = `
        <div class="admin-ai-form">
            <div class="wf-history-head"><strong>${t('admin.skills.import-title')}</strong></div>
            <div class="wf-note">${t('admin.skills.import-intro')}</div>
            <div class="job-form-grid">
                <label>${t('admin.skills.import-source')}</label>
                <div class="wf-checks">
                    <label class="wf-check"><input type="radio" name="sk-src" value="repo" checked ${data.can_git ? '' : 'disabled'}> ${t('admin.skills.src-repo')}</label>
                    <label class="wf-check"><input type="radio" name="sk-src" value="zip" ${data.can_zip ? '' : 'disabled'}> ${t('admin.skills.src-zip')}</label>
                </div>
                <label class="sk-repo">${t('admin.skills.repo-url')}</label>
                <div class="sk-repo"><input type="text" id="sk-i-url" class="form-control" placeholder="https://github.com/anthropics/skills">
                    <div class="wf-sub">${t('admin.skills.repo-hint')}</div></div>
                <label class="sk-repo">${t('admin.skills.repo-branch')}</label>
                <input type="text" id="sk-i-branch" class="form-control sk-repo" placeholder="main">
                <label class="sk-repo">${t('admin.skills.repo-path')}</label>
                <input type="text" id="sk-i-path" class="form-control sk-repo" placeholder="skills">
                <label class="sk-zip hidden">${t('admin.skills.zip-file')}</label>
                <input type="file" id="sk-i-file" class="form-control sk-zip hidden" accept=".zip,application/zip">
            </div>
            ${data.can_zip ? '' : `<div class="wf-note wf-note-warn">${t('admin.skills.no-zip')}</div>`}
            <div style="display:flex;gap:0.5rem;margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid var(--border-color)">
                <button id="sk-i-cancel" class="btn btn-secondary">${t('btn.cancel')}</button>
                <button id="sk-i-scan" class="btn btn-blue" style="margin-left:auto">${t('admin.skills.scan-btn')}</button>
            </div>
        </div>`;
    const setSrc = (v) => {
        box.querySelectorAll('.sk-repo').forEach(n => n.classList.toggle('hidden', v !== 'repo'));
        box.querySelectorAll('.sk-zip').forEach(n => n.classList.toggle('hidden', v !== 'zip'));
    };
    box.querySelectorAll('input[name="sk-src"]').forEach(r => r.addEventListener('change', () => setSrc(r.value)));
    if (!data.can_git && data.can_zip) { box.querySelector('input[value="zip"]').checked = true; setSrc('zip'); }
    box.querySelector('#sk-i-cancel').addEventListener('click', renderList);
    const scanBtn = box.querySelector('#sk-i-scan');
    scanBtn.addEventListener('click', async () => {
        const src = box.querySelector('input[name="sk-src"]:checked')?.value;
        const params = { source: src };
        if (src === 'zip') {
            const f = box.querySelector('#sk-i-file').files[0];
            if (!f) { showToast(t('admin.skills.zip-missing'), 'error'); return; }
            params.file = f;
        } else {
            params.url    = box.querySelector('#sk-i-url').value.trim();
            params.branch = box.querySelector('#sk-i-branch').value.trim();
            params.path   = box.querySelector('#sk-i-path').value.trim();
            if (!params.url) { showToast(t('admin.skills.url-missing'), 'error'); return; }
        }
        scanBtn.disabled = true;
        scanBtn.textContent = t('admin.skills.scanning');
        const res = await api.call('admin_skill_import_stage', params, 'POST');
        scanBtn.disabled = false;
        scanBtn.textContent = t('admin.skills.scan-btn');
        if (!res.success) { showToast(res.message || t('admin.skills.import-fail'), 'error'); return; }
        renderCandidates(res);
    });
};

const renderCandidates = async (stage) => {
    const box = container();
    // The destination decides which names already exist there, so it is chosen first and
    // re-checked whenever it changes.
    let dest = scope;
    const existing = {};
    const loadExisting = async (s) => {
        if (existing[s]) return existing[s];
        const r = await api.call('admin_skills_list', { scope: s });
        existing[s] = new Set((r.skills || []).map(x => x.folder));
        return existing[s];
    };

    const draw = async () => {
        const have = await loadExisting(dest);
        const rows = stage.skills.map((c, i) => {
            const taken = c.valid && have.has(c.name);
            const choice = !c.valid ? '' : taken
                ? `<select class="form-control sk-c-choice" data-i="${i}">
                       <option value="skip">${t('admin.skills.choice-skip')}</option>
                       <option value="overwrite">${t('admin.skills.choice-overwrite')}</option></select>`
                : `<label class="wf-check"><input type="checkbox" class="sk-c-choice" data-i="${i}" checked> ${t('admin.skills.choice-import')}</label>`;
            const dropped = c.dropped_count
                ? `<details class="wf-sub"><summary>${esc(t('admin.skills.dropped-n', { n: c.dropped_count }))}</summary>`
                  + c.dropped.map(d => `<div><code>${esc(d.path)}</code> — ${esc(reasonText(d.reason))}</div>`).join('') + `</details>` : '';
            return `<tr>
                <td>${choice}</td>
                <td class="admin-td-name"><code>${esc(c.name || c.key || '—')}</code>
                    ${c.key ? `<div class="wf-sub">${esc(c.key)}/</div>` : ''}
                    ${c.files ? `<div class="wf-sub">${esc(t('admin.skills.files-n', { n: c.files }))}</div>` : ''}</td>
                <td class="skills-desc">${esc(c.description)}${dropped}</td>
                <td>${c.valid
                    ? (taken ? `<span class="wf-sub">${esc(t('admin.skills.exists'))}</span>` : `<span class="admin-job-status admin-job-status-ok">${t('admin.skills.valid')}</span>`)
                    : `<span class="admin-job-status admin-job-status-err">${t('admin.skills.invalid')}</span>` + c.errors.map(e => `<div class="wf-err">${esc(errText(e))}</div>`).join('')}</td>
            </tr>`;
        }).join('');
        box.innerHTML = `
            <div class="admin-ai-form">
                <div class="wf-history-head"><strong>${t('admin.skills.found-title', { n: stage.skills.length })}</strong>
                    <span class="wf-sub">${esc(stage.from)}</span></div>
                <div class="wf-note">${t('admin.skills.found-hint')}</div>
                <div class="skills-head">
                    <label for="sk-c-dest">${t('admin.skills.import-into')}</label>
                    <select id="sk-c-dest" class="form-control">${scopeOptions(dest)}</select>
                </div>
                <table class="admin-table wf-table"><thead><tr><th></th><th>${t('admin.skills.col-name')}</th>
                    <th>${t('admin.skills.col-desc')}</th><th>${t('admin.skills.col-status')}</th></tr></thead><tbody>${rows}</tbody></table>
                <div style="display:flex;gap:0.5rem;margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid var(--border-color)">
                    <button id="sk-c-cancel" class="btn btn-secondary">${t('btn.cancel')}</button>
                    <button id="sk-c-go" class="btn btn-green" style="margin-left:auto">${t('admin.skills.import-go')}</button>
                </div>
            </div>`;
        box.querySelector('#sk-c-dest').addEventListener('change', (e) => { dest = e.target.value; draw(); });
        box.querySelector('#sk-c-cancel').addEventListener('click', renderList);
        box.querySelector('#sk-c-go').addEventListener('click', async () => {
            const choices = {};
            box.querySelectorAll('.sk-c-choice').forEach(n => {
                const c = stage.skills[+n.dataset.i];
                choices[c.key] = n.tagName === 'SELECT' ? n.value : (n.checked ? 'import' : 'skip');
            });
            if (!Object.values(choices).some(v => v !== 'skip')) { showToast(t('admin.skills.nothing-chosen'), 'error'); return; }
            const res = await api.call('admin_skill_import_commit',
                { import_id: stage.import_id, scope: dest, choices: JSON.stringify(choices) }, 'POST');
            if (!res.success) { showToast(res.message || t('admin.skills.import-fail'), 'error'); return; }
            invalidateSkills();
            const parts = [t('admin.skills.imported-n', { n: res.imported.length })];
            if (res.skipped.length) parts.push(t('admin.skills.skipped-n', { n: res.skipped.length }));
            if (res.errors.length) parts.push(t('admin.skills.failed-n', { n: res.errors.length }));
            showToast(parts.join(' · '), res.errors.length ? 'error' : 'success');
            scope = dest;
            loadSkills();
        });
    };
    await draw();
};

export const initSkills = () => {
    document.getElementById('admin-skills-add-btn')?.addEventListener('click', () => openEditor(null));
    document.getElementById('admin-skills-import-btn')?.addEventListener('click', openImport);
};
