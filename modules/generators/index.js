// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Page generators in the browser (the engine is page_generators.php):
 *
 *  - a banner above a page that a generator wrote ("Generated from …", with Detach), one
 *    that it archived or that was detached from it, and above a template itself — what it
 *    reads, where it writes, how the last render went — with Re-render now and Preview;
 *  - New → Generated pages…, which writes a template for a chosen list and opens it.
 *
 * **The header is folded away on a generated, archived or detached page**: the page is for
 * reading, and "where this comes from" is a detail. A coloured icon in the page header (beside
 * the Metadata badge, `#generator-badge`) says the page belongs to a generator, and opens or
 * closes the header. A template shows it open — it is the template's control panel — and the
 * icon closes it. Each page opened starts that way again.
 *
 * Everything it shows comes from the `get` that rendered the page (`generated`,
 * `generator`), so the banner follows the same `wiki:frontmatter` event the Metadata badge
 * does and never asks the server on its own. A generated page also gets
 * `body.page-generated`, which hides the edit button; setEditingMode() refuses as well,
 * because Ctrl+E clicks a hidden button just the same.
 */
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { icons } from '../core/icons.js';
import { showToast, confirmModal } from '../core/utils.js';
import { t } from '../i18n/index.js';
import { scopeStyles } from '../core/scoped_css.js';
import { initEditButtons } from './edit_item.js';
import { openTemplateHelp, templateFields } from './help.js';

const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');

const canEdit = () => window.WIKI_ROLE !== 'reader' && !state.spaceReadOnly;

let banner = null;
let badge = null;
// Whether the header is open, and for which page: opening another page starts it afresh.
let openFor = null;
let open = false;

// ── Navigation ───────────────────────────────────────────────────────────────

/** Open a page by id, in another Space if need be — the way job_watch does it. */
export const openPage = async (space, id) => {
    if (space && space !== state.currentSpace) {
        const { switchSpaceSilently } = await import('../spaces/index.js');
        const { refreshFileTree } = await import('../file_tree/index.js');
        switchSpaceSilently(space);
        await refreshFileTree();
    }
    const res = await api.call('get_path_from_id', { pageid: id });
    if (!res.success || !res.path) { showToast(t('generators.not-found'), 'error'); return; }
    const { loadPage } = await import('../page_view/index.js');
    const { revealAndSelectFile } = await import('../file_tree/index.js');
    await loadPage(res.path, id, []);
    revealAndSelectFile(res.path);
};

const pageLink = (space, id, label) =>
    `<a href="?pageid=${encodeURIComponent(id)}&space=${encodeURIComponent(space || '')}" class="generator-link"`
    + ` data-space="${esc(space || '')}" data-id="${esc(id)}">${esc(label)}</a>`;

const templateLink = (g) => g.template_path
    ? pageLink(state.currentSpace, g.template_id, g.template_path.replace(/\.md$/i, ''))
    : `<em>${esc(t('generators.template-gone'))}</em>`;

// ── The banner ───────────────────────────────────────────────────────────────

// A template syntax error arrives as "<code>: <detail>" (mustache.php's codes).
const tplErrorText = (detail) => {
    const m = /^([a-z_]+)(?::\s*(.*))?$/.exec(String(detail ?? ''));
    if (!m) return String(detail ?? '');
    const key = `generators.tpl.${m[1]}`;
    // Where a value may not go in HTML is named in English by the server; say it in words.
    let x = m[2] ?? '';
    if (m[1] === 'html_context') {
        const where = t(`generators.html-where.${x.replace(/ /g, '_')}`);
        if (!where.startsWith('generators.')) x = where;
    }
    const text = t(key, { x });
    return text === key ? String(detail) : text;
};

export const errorText = (info) => {
    const detail = ['template_error', 'filename_error'].includes(info.error) ? tplErrorText(info.detail) : (info.detail ?? '');
    const vars = { detail, line: info.line ?? '', owner: info.owner ?? info.detail ?? '' };
    const key = `generators.error.${info.error}`;
    const text = t(key, vars);
    return text === key ? t('generators.error.other', vars) : text;
};

export const warningText = (w) => {
    const key = `generators.warning.${w.warning}`;
    const text = t(key, { detail: w.detail ?? '' });
    return text === key ? (w.detail ?? '') : text;
};

export const countsText = (c) => {
    if (!c) return '';
    const parts = ['created', 'updated', 'renamed', 'archived']
        .filter(k => c[k] > 0).map(k => t(`generators.count.${k}`, { n: c[k] }));
    return parts.length ? parts.join(', ') : t('generators.count.none');
};

const templateBanner = (g) => {
    const src = g.source;
    const source = !src ? '—'
        : (src.readable && src.path ? pageLink(src.space, src.id, `${src.space} / ${src.path.replace(/\.list$/i, '')}`)
                                    : esc(`${src.space} #${src.id}`));
    const lines = [
        `<strong>${esc(t('generators.banner-template'))}</strong>`,
        `<div>${t('generators.template-reads', { source, folder: esc(g.folder || '/'), n: g.pages })}</div>`,
    ];
    let status;
    if (g.pending)               status = `<span class="generator-status pending">${esc(t('generators.status-pending'))}</span>`;
    else if (g.error)            status = `<span class="generator-status error">${esc(errorText(g))}</span>`;
    else if (g.status === 'ok')  status = `<span class="generator-status ok">${esc(t('generators.status-ok', {
        when: g.last_run ? new Date(g.last_run).toLocaleString() : '', owner: g.owner || '—', counts: countsText(g.counts) }))}</span>`;
    else                         status = `<span class="generator-status">${esc(t('generators.status-never'))}</span>`;
    lines.push(`<div>${status}</div>`);
    for (const w of (g.warnings || [])) lines.push(`<div class="generator-warning">${esc(warningText(w))}</div>`);
    const buttons = [];
    if (src?.readable) buttons.push(`<button type="button" class="btn btn-secondary btn-sm" data-gen="preview">${esc(t('generators.preview'))}</button>`);
    if (canEdit()) buttons.push(`<button type="button" class="btn btn-blue btn-sm" data-gen="render">${esc(t('generators.render-now'))}</button>`);
    if (canEdit()) buttons.push(`<button type="button" class="btn btn-secondary btn-sm" data-gen="settings">${esc(t('generators.settings'))}</button>`);
    // What the template may say ({{_title}}, sections, repeats …) — help.js.
    buttons.push(`<button type="button" class="btn btn-secondary btn-sm gen-help-btn" data-gen="help" title="${esc(t('generators.help-title'))}" aria-label="${esc(t('generators.help-title'))}">?</button>`);
    return `${lines.join('')}<div class="generator-actions">${buttons.join('')}</div>`;
};

const pageBanner = (g) => {
    if (g.state === 'generated') {
        const detach = canEdit()
            ? `<div class="generator-actions"><button type="button" class="btn btn-secondary btn-sm" data-gen="detach">${esc(t('generators.detach'))}</button></div>` : '';
        return `<div>${t('generators.banner-generated', { template: templateLink(g), row: esc(g.row) })}</div>${detach}`;
    }
    // Archived for one of two reasons, which must not be confused: the list item was deleted,
    // or the template was (or stopped generating). A page archived before the reason was
    // recorded gets a wording that claims neither.
    if (g.state === 'archived') {
        if (g.reason === 'row_removed') return `<div>${t('generators.banner-archived-row', { template: templateLink(g), list: esc(g.list || '') })}</div>`;
        if (g.reason === 'template_gone') return `<div>${esc(t('generators.banner-archived-template'))}</div>`;
        return `<div>${t('generators.banner-archived', { template: templateLink(g) })}</div>`;
    }
    return `<div>${t('generators.banner-detached', { template: templateLink(g) })}</div>`;
};

/**
 * While a template is edited its banner is hidden (it describes the saved page), so the help
 * is offered in the editor toolbar instead — a button of ours, there only for a template.
 */
const syncToolbarHelp = () => {
    const toolbar = document.getElementById('editor-toolbar');
    if (!toolbar) return;
    let btn = document.getElementById('gen-template-help-btn');
    if (!btn) {
        btn = document.createElement('button');
        btn.id = 'gen-template-help-btn';
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-secondary gen-toolbar-help hidden';
        btn.textContent = '{{ }} ?';
        btn.addEventListener('mousedown', e => e.preventDefault());
        btn.addEventListener('click', (e) => { e.preventDefault(); openTemplateHelp(dialog); });
        toolbar.appendChild(btn);
    }
    btn.title = t('generators.help-title');
    btn.setAttribute('aria-label', t('generators.help-title'));
    btn.classList.toggle('hidden', !(state.isEditing && state.currentPageType === 'md' && state.currentPageGenerator));
};

export const renderBanner = () => {
    syncToolbarHelp();
    if (!banner) return;
    const md = state.currentPageType === 'md' && !state.isEditing;
    const gen = md ? state.currentPageGenerated : null;
    const tpl = md ? state.currentPageGenerator : null;
    document.body.classList.toggle('page-generated', state.currentPageType === 'md' && state.currentPageGenerated?.state === 'generated');
    if (!gen && !tpl) {
        banner.classList.add('hidden');
        banner.innerHTML = '';
        badge?.classList.add('hidden');
        // Another page is open: forget that the header was unfolded, or coming back to a
        // generated page (a link, a tab) would show it open. Not while editing, which hides
        // the banner without leaving the page.
        if (!state.isEditing) openFor = null;
        return;
    }
    // A page newly opened: a template's header open, any other's folded away.
    const key = `${state.currentSpace}:${state.currentPagePath}`;
    if (openFor !== key) { openFor = key; open = !!tpl; }

    if (badge) {
        badge.classList.remove('hidden');
        badge.classList.toggle('is-active', open);
        // Coloured while the page is generated (or is a template); grey once it has left.
        badge.classList.toggle('is-muted', !tpl && gen.state !== 'generated');
        badge.title = t(tpl ? 'generators.badge-template' : `generators.badge-${gen.state}`);
        badge.setAttribute('aria-expanded', String(open));
    }
    banner.className = `generator-banner ${tpl ? 'is-template' : `is-${gen.state}`}` + (open ? '' : ' hidden');
    banner.innerHTML = `<span class="generator-icon">${icons.generator}</span><div class="generator-body">${tpl ? templateBanner(tpl) : pageBanner(gen)}</div>`;
};

/** Re-read the open page's `get` so the banner shows the state after an action. */
const refreshInfo = async () => {
    if (!state.currentPagePath) return;
    const res = await api.call('get', { file: state.currentPagePath });
    if (!res.success) return;
    state.currentPageGenerated = res.generated ?? null;
    state.currentPageGenerator = res.generator ?? null;
    renderBanner();
    return res;
};

/** A render runs after the response; while it is pending, look again a few times. */
const followPending = async (tries = 5) => {
    for (let i = 0; i < tries && state.currentPageGenerator?.pending; i++) {
        await new Promise(r => setTimeout(r, 2000));
        await refreshInfo();
    }
};

const renderNow = async () => {
    const res = await api.call('generator_render', { id: state.currentPageId }, 'POST');
    if (res.status === 'ok') showToast(t('generators.rendered', { counts: countsText(res.counts) }), 'success');
    else showToast(res.error ? errorText(res) : (res.message || t('generators.error.other', { detail: '' })), 'error');
    await refreshInfo();
};

const detach = async () => {
    const ok = await confirmModal(t('generators.detach-title'), {
        message: t('generators.detach-confirm'), confirmLabel: t('generators.detach'), cancelLabel: t('btn.cancel') });
    if (!ok) return;
    const res = await api.call('generator_detach', { path: state.currentPagePath }, 'POST');
    if (!res.success) { showToast(res.message || t('generators.error.other', { detail: '' }), 'error'); return; }
    showToast(t('generators.detached'), 'success');
    const { loadPage } = await import('../page_view/index.js');
    await loadPage(state.currentPagePath, state.currentPageId, state.currentPageTags || []);
};

// ── A small dialog of our own (the shared confirm modal holds no form) ──────

const dialog = (title, bodyHtml, { okLabel = '', wide = false } = {}) => {
    const overlay = document.createElement('div');
    overlay.className = 'lightbox-overlay generator-dialog';
    overlay.innerHTML = `<div class="lightbox-content ${wide ? 'generator-dialog-wide' : 'lightbox-content-sm'}">
        <button type="button" class="lightbox-close" data-gen-close>&times;</button>
        <h3>${esc(title)}</h3>
        <div class="generator-dialog-body">${bodyHtml}</div>
        <div class="lightbox-footer">
            <button type="button" class="btn btn-secondary" data-gen-close>${esc(t(okLabel ? 'btn.cancel' : 'btn.close'))}</button>
            ${okLabel ? `<button type="button" class="btn btn-green" data-gen-ok>${esc(okLabel)}</button>` : ''}
        </div></div>`;
    document.body.appendChild(overlay);
    const close = () => overlay.remove();
    overlay.addEventListener('click', (e) => { if (e.target === overlay || e.target.closest('[data-gen-close]')) close(); });
    overlay.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
    return { overlay, close };
};

const preview = async () => {
    const { overlay } = dialog(t('generators.preview-title'),
        `<div class="form-group"><label class="form-label">${esc(t('generators.preview-row'))}</label>
         <select class="form-control" data-gen-row></select></div>
         <div class="generator-preview-name"></div>
         <div class="generator-preview-warnings"></div>
         <div class="generator-preview markdown-body"></div>`, { wide: true });
    const sel = overlay.querySelector('[data-gen-row]');
    const show = async (row) => {
        const res = await api.call('generator_preview', { id: state.currentPageId, ...(row != null ? { row } : {}) });
        const out = overlay.querySelector('.generator-preview');
        if (!res.success) { out.textContent = res.error ? errorText(res) : (res.message || ''); return; }
        if (!sel.options.length) {
            sel.innerHTML = res.rows.map(r => `<option value="${esc(r.id)}">${esc(r.title || `#${r.id}`)}</option>`).join('');
        }
        sel.value = String(res.row ?? '');
        overlay.querySelector('.generator-preview-name').textContent = res.rows.length ? t('generators.preview-name', { name: `${res.name}.md` }) : t('generators.preview-empty');
        overlay.querySelector('.generator-preview-warnings').innerHTML =
            (res.warnings || []).map(w => `<div class="generator-warning">${esc(warningText(w))}</div>`).join('');
        // Rendered by the server, values already escaped for where they land: an HTML
        // template's output is shown as it is, a Markdown one through marked.
        out.innerHTML = res.format === 'html' ? (res.body || '')
            : (window.marked ? window.marked.parse(res.body || '') : esc(res.body || ''));
        // The template's own <style> reaches only the preview, not the wiki around it.
        if (res.format === 'html') scopeStyles(out, '.generator-dialog .generator-preview');
    };
    sel.addEventListener('change', () => show(sel.value));
    await show(null);
};

// ── New → Generated pages… ───────────────────────────────────────────────────

// Folders a generator may write into: the open Space's, from the tree data the sidebar
// already holds — not a files library (it takes files, not pages), nothing dot-named, and not
// the top-level folders page_generators.php reserves (WIKI_GEN_RESERVED_DIRS).
const GEN_RESERVED = ['skills', 'memory', 'templates'];
const pickableFolders = () => {
    const out = [];
    const walk = (items, depth) => (items || [])
        .filter(i => i.type === 'folder' && !i.name.startsWith('.'))
        .filter(i => depth > 0 || !GEN_RESERVED.includes(i.name.toLowerCase()))
        .sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base', numeric: true }))
        .forEach(i => { out.push({ path: i.path, name: i.name, depth }); walk(i.children, depth + 1); });
    walk(state.fullFileTree, 0);
    return out;
};

/**
 * The Space's folders under "Pages go in folder", always shown: the Space root first, the rest
 * indented by depth. Clicking one fills the box; the folder the box names is highlighted, as it
 * is typed too. A name that is not a folder yet still works — the generator creates it.
 */
const folderPicker = (overlay, input, onPick) => {
    const box = overlay.querySelector('[data-gen-folder-tree]');
    const mark = () => {
        const want = input.value.trim().replace(/^\/+|\/+$/g, '');
        box.querySelectorAll('.gen-folder-row').forEach(r => {
            const on = r.dataset.path === want;
            r.classList.toggle('is-current', on);
            r.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        box.querySelector('.is-current')?.scrollIntoView({ block: 'nearest' });
    };
    const row = (path, label, depth) => {
        const r = document.createElement('button');
        r.type = 'button';
        r.className = 'gen-folder-row';
        r.dataset.path = path;
        r.style.paddingLeft = `${0.5 + depth * 1.1}rem`;
        r.setAttribute('role', 'option');
        r.innerHTML = `${icons.folder}<span></span>`;
        r.querySelector('span').textContent = label;
        r.addEventListener('click', () => {
            input.value = path;
            onPick();
            mark();
        });
        return r;
    };
    box.appendChild(row('', t('fileops.root'), 0));
    pickableFolders().forEach(f => box.appendChild(row(f.path, f.name, f.depth + 1)));
    input.addEventListener('input', mark);
    mark();
    return mark;
};

/**
 * Settings… on a template: where its pages go and what they are called — the two
 * generator keys a person most often wants to change, without editing front matter (which a
 * Space may not even allow by hand). Saved by `generator_settings`; the render that follows
 * moves or renames the pages, which keep their ids. The list and the output format are not
 * here: changing either is rewriting the template, not adjusting it.
 */
const settingsDialog = async () => {
    const g = state.currentPageGenerator;
    if (!g) return;
    const mine = await templateFields();
    // The same choices as the New dialog: any field but the Title, which is the default.
    const fields = (mine?.fields || []).filter(f => !f.title);
    const current = String(state.currentPageFrontmatter?.generator_filename ?? '').trim();
    const options = [['', t('generators.new-name-title')], ...fields.map(f => [`{{${f.key}}}`, f.name])];
    // A name the dialog cannot express (several fields, words around them) is kept as it is.
    if (current && current !== '{{_title}}' && !options.some(([v]) => v === current)) {
        options.push([current, t('generators.settings-name-custom', { x: current })]);
    }
    const { overlay, close } = dialog(t('generators.settings-title'), `
        <div class="form-group"><label class="form-label">${esc(t('generators.new-folder'))}</label>
            <input type="text" class="form-control" data-gen-folder>
            <div class="gen-folder-tree" data-gen-folder-tree role="listbox"></div></div>
        <div class="form-group"><label class="form-label">${esc(t('generators.new-name-from'))}</label>
            <select class="form-control" data-gen-name>${options.map(([v, l]) =>
                `<option value="${esc(v)}">${esc(l)}</option>`).join('')}</select></div>
        <p class="generator-hint">${esc(t('generators.settings-hint'))}</p>`, { okLabel: t('btn.save') });
    const folderEl = overlay.querySelector('[data-gen-folder]');
    const nameEl = overlay.querySelector('[data-gen-name]');
    folderEl.value = g.folder || '';
    nameEl.value = current === '{{_title}}' ? '' : current;
    folderPicker(overlay, folderEl, () => {});
    overlay.querySelector('[data-gen-ok]').addEventListener('click', async () => {
        const r = await api.call('generator_settings',
            { path: state.currentPagePath, folder: folderEl.value.trim(), filename: nameEl.value }, 'POST');
        if (!r.success) {
            showToast(r.error === 'bad_folder' ? t('generators.error.bad_folder', { detail: r.detail || '' })
                : (r.message || t('generators.error.other', { detail: '' })), 'error');
            return;
        }
        close();
        if (r.unchanged) return;
        showToast(t('generators.settings-saved'), 'success');
        // The template changed on disk under us: re-read it (no "changed on disk" reload), then
        // follow the render that moves the pages.
        const { refreshPageContent } = await import('../page_view/index.js');
        await refreshPageContent();
        followPending();
    });
    folderEl.focus();
};

const createDialog = async () => {
    const res = await api.call('generator_sources');
    const lists = res.success ? res.data : [];
    if (!lists.length) { showToast(t('generators.no-lists'), 'info'); return; }
    const opts = lists.map((l, i) => `<option value="${i}">${esc(`${l.space} / ${l.path.replace(/\.list$/i, '')}`)} (${esc(t('generators.rows', { n: l.rows }))})</option>`).join('');
    const { overlay, close } = dialog(t('generators.new-title'), `
        <p class="generator-hint">${esc(t('generators.new-hint'))}</p>
        <div class="form-group"><label class="form-label">${esc(t('generators.new-source'))}</label>
            <select class="form-control" data-gen-source>${opts}</select></div>
        <div class="form-group"><label class="form-label">${esc(t('generators.new-template'))}</label>
            <input type="text" class="form-control" data-gen-path></div>
        <div class="form-group"><label class="form-label">${esc(t('generators.new-folder'))}</label>
            <input type="text" class="form-control" data-gen-folder>
            <div class="gen-folder-tree" data-gen-folder-tree role="listbox"></div></div>
        <div class="form-group"><label class="form-label">${esc(t('generators.new-name-from'))}</label>
            <select class="form-control" data-gen-name></select></div>
        <div class="form-group"><label class="form-label">${esc(t('generators.new-output'))}</label>
            <label class="pref-check"><input type="radio" name="gen-format" value="html" data-gen-format checked> ${esc(t('generators.new-output-html'))}</label>
            <label class="pref-check"><input type="radio" name="gen-format" value="markdown" data-gen-format> ${esc(t('generators.new-output-md'))}</label></div>`, { okLabel: t('btn.create') });
    const src = overlay.querySelector('[data-gen-source]');
    const pathEl = overlay.querySelector('[data-gen-path]');
    const folderEl = overlay.querySelector('[data-gen-folder]');
    const nameEl = overlay.querySelector('[data-gen-name]');
    // Defaults follow the chosen list until the person types something of their own.
    const typed = new Set();
    [pathEl, folderEl].forEach(el => el.addEventListener('input', () => typed.add(el)));
    const fill = () => {
        const l = lists[Number(src.value)];
        // A Space's `templates/` folder (lowercase, as a new Space gets it) — the New Page
        // dialog leaves generator templates out of its list (list_md_templates).
        if (!typed.has(pathEl)) pathEl.value = `templates/${l.title}`;
        if (!typed.has(folderEl)) folderEl.value = l.title;
        nameEl.innerHTML = `<option value="">${esc(t('generators.new-name-title'))}</option>`
            + l.fields.filter(f => !f.title).map(f => `<option value="${esc(f.key)}">${esc(f.name)}</option>`).join('');
    };
    fill();
    const markFolder = folderPicker(overlay, folderEl, () => typed.add(folderEl));
    src.addEventListener('change', () => { fill(); markFolder(); });
    overlay.querySelector('[data-gen-ok]').addEventListener('click', async () => {
        const l = lists[Number(src.value)];
        const r = await api.call('generator_create', {
            source: `${l.space}:${l.id}`, path: pathEl.value.trim(), folder: folderEl.value.trim(), filename_key: nameEl.value,
            format: overlay.querySelector('[data-gen-format]:checked')?.value || 'html',
        }, 'POST');
        if (!r.success) {
            showToast(r.error === 'exists' ? t('generators.exists') : (r.error ? errorText(r) : (r.message || '')), 'error');
            return;
        }
        close();
        showToast(t('generators.created'), 'success');
        const { refreshFileTree } = await import('../file_tree/index.js');
        await refreshFileTree();
        await openPage(state.currentSpace, r.id);
    });
    pathEl.focus();
};

export const init = () => {
    // {{_edit}} buttons on generated pages (edit_item.js).
    initEditButtons();
    const viewer = document.getElementById('viewer-content');
    if (viewer && !document.getElementById('generator-banner')) {
        banner = document.createElement('div');
        banner.id = 'generator-banner';
        banner.className = 'generator-banner hidden';
        viewer.parentNode.insertBefore(banner, viewer);
        banner.addEventListener('click', (e) => {
            const link = e.target.closest('.generator-link');
            if (link) { e.preventDefault(); openPage(link.dataset.space, Number(link.dataset.id)); return; }
            const action = e.target.closest('[data-gen]')?.dataset.gen;
            if (action === 'render') renderNow();
            else if (action === 'detach') detach();
            else if (action === 'preview') preview();
            else if (action === 'help') openTemplateHelp(dialog);
            else if (action === 'settings') settingsDialog();
        });
    }
    // The icon in the page header that opens and closes it, beside the Metadata badge.
    const fm = document.getElementById('frontmatter-badge');
    if (fm && !document.getElementById('generator-badge')) {
        badge = document.createElement('button');
        badge.id = 'generator-badge';
        badge.type = 'button';
        badge.className = 'btn btn-icon btn-secondary generator-badge hidden';
        badge.innerHTML = icons.generator;
        badge.addEventListener('click', () => { open = !open; renderBanner(); });
        fm.parentNode.insertBefore(badge, fm);
    }
    document.addEventListener('wiki:frontmatter', () => { renderBanner(); followPending(); });
    document.addEventListener('wiki:pagestate', renderBanner);
    // Opening a list or a chat does not raise wiki:frontmatter; the type change does.
    window.addEventListener('wiki:pagetype', renderBanner);

    // New → Generated pages…, after JSON Data. Not for readers, who have no New menu anyway.
    const dropdown = document.getElementById('new-item-dropdown');
    if (dropdown && window.WIKI_ROLE !== 'reader' && !document.getElementById('dropdown-new-generator')) {
        const a = document.createElement('a');
        a.href = '#';
        a.id = 'dropdown-new-generator';
        a.innerHTML = `${icons.generator} ${esc(t('nav.new-generator'))}`;
        a.addEventListener('click', (e) => { e.preventDefault(); dropdown.classList.add('hidden'); createDialog(); });
        const after = document.getElementById('dropdown-new-json');
        if (after?.nextSibling) dropdown.insertBefore(a, after.nextSibling); else dropdown.appendChild(a);
    }
};
