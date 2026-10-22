// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// Admin → Content → Taxonomy: the tagging mode, a tree editor, the tags pages use that are
// not in the tree, and export/import.
//
// The editor is a file-manager shape on purpose — folders group, items are the tags pages
// carry (and may hold narrower items), each with its icon:
//  - **One toolbar**, whose buttons act on the selected node and say in their tooltip why
//    they are disabled, instead of five buttons repeated on every row.
//  - **A details panel** for the selected node (type, name, description, usage), because a
//    description does not fit in a tree row and an edit dialog hides the tree it belongs to.
//  - **Inline rename** (double-click or F2), **drag and drop to move**, and the keyboard
//    model of a tree (arrows, Left/Right to collapse/expand, F2, Delete). Merge stays an
//    explicit button: it is destructive, and a drop that lands one row off must not do it.
// Every change is one request (taxonomy.php decides and retags pages); the editor reloads
// from the answer and reselects where the node went.
import { api } from '../core/api.js';
import { showToast, confirmModal } from '../core/utils.js';
import { icons } from '../core/icons.js';
import { t } from '../i18n/index.js';
import { flatten, invalidateTaxonomy, lc, boundFields, sortNodes } from '../core/taxonomy.js';

const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
};
const svg = (inner) => `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${inner}</svg>`;
const TB_ICONS = {
    move:     svg('<polyline points="5 9 2 12 5 15"/><polyline points="9 5 12 2 15 5"/><polyline points="15 19 12 22 9 19"/><polyline points="19 9 22 12 19 15"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="12" y1="2" x2="12" y2="22"/>'),
    merge:    svg('<circle cx="18" cy="18" r="3"/><circle cx="6" cy="6" r="3"/><path d="M6 21V9a9 9 0 0 0 9 9"/>'),
    del:      svg('<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/>'),
    expand:   svg('<polyline points="7 13 12 18 17 13"/><polyline points="7 6 12 11 17 6"/>'),
    collapse: svg('<polyline points="17 11 12 6 7 11"/><polyline points="17 18 12 13 7 18"/>'),
};

let _data = { mode: 'free', nodes: [], counts: {}, unclassified: [], fieldCounts: {}, misplaced: [] };
let _flat = [];               // flatten(_data.nodes)
let _byKey = new Map();       // lower-case path → flat node
let _fields = new Map();      // bound front-matter fields (core/taxonomy.js boundFields)
const _collapsed = new Set(); // lower-case paths
let _selected = null;         // spelled path
let _dragPath = null;
let _ui = {};                 // the live elements of the editor, while it is open
let _pane = null;             // the tab's own elements: summary, misplaced note, unclassified

// ── Data ─────────────────────────────────────────────────────────────────────

const load = async () => {
    const r = await api.call('admin_taxonomy', { space: '' });
    if (!r.success) { showToast(r.message || t('admin.tax.load-failed'), 'error'); return false; }
    _data = { mode: r.mode, nodes: r.nodes || [], counts: r.counts || {}, unclassified: r.unclassified || [],
              fieldCounts: r.field_counts || {}, misplaced: r.misplaced || [] };
    _fields = boundFields(_data.nodes);
    // Folders first, then items, alphabetical — the order the tree, the arrow keys and
    // the Move/Merge lists all follow.
    _flat = flatten(sortNodes(_data.nodes));
    _byKey = new Map(_flat.map(n => [lc(n.path), n]));
    if (_selected && !_byKey.has(lc(_selected))) _selected = null;
    invalidateTaxonomy();
    return true;
};

const parentOf = (path) => path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : '';
const nameOf   = (path) => path.slice(path.lastIndexOf('/') + 1);
const under    = (path, root) => lc(path) === lc(root) || lc(path).startsWith(lc(root) + '/');
const exactUse = (path) => _data.counts[lc(path)] || 0;
const belowUse = (path) => Object.entries(_data.counts)
    .filter(([k]) => k.startsWith(lc(path) + '/')).reduce((a, [, n]) => a + n, 0);
/**
 * Pages using `path`, or an item below it, as the value of a bound field. A value is a
 * name, so this counts the names of the item and its descendants under each field whose
 * branch holds them.
 */
const valueUse = (path) => {
    let n = 0;
    for (const f of _fields.values()) {
        if (!under(path, f.path) || lc(path) === lc(f.path)) continue;
        const counts = _data.fieldCounts[lc(f.field)] || {};
        for (const x of _flat) {
            if (!x.heading && under(x.path, path)) n += counts[lc(x.name)] || 0;
        }
    }
    return n;
};
const inUse    = (path) => exactUse(path) + belowUse(path) + valueUse(path);

// A folder sits at the top level or inside another folder — never inside an item
// (taxonomy.php says why). These are the client's copies of that rule, so a button is off,
// a drop is not offered and a choice is not listed before the server would refuse it.
const isItem = (path) => !!path && !!_byKey.get(lc(path)) && !_byKey.get(lc(path)).heading;
const hasChildFolder = (path) => _flat.some(x => x.heading && lc(parentOf(x.path)) === lc(path));
/** May the node at `path` go into `parent` ('' = top level)? */
const mayHold = (parent, path) => !(_byKey.get(lc(path))?.heading && isItem(parent));
/** The bound field `path` is a value of, if any (nearest bound ancestor). */
const fieldOfValue = (path) => {
    let best = null;
    for (const f of _fields.values()) {
        if (under(path, f.path) && lc(path) !== lc(f.path) && (!best || f.path.length > best.path.length)) best = f;
    }
    return best;
};

/**
 * Run one tree operation, reload, and select `next` (where the node went). Returns the
 * server's answer so a caller can tell success from refusal.
 */
const op = async (params, doneKey, next) => {
    const r = await api.call('admin_taxonomy_node', { space: '', ...params }, 'POST');
    if (!r.success) { showToast(r.message || t('admin.tax.failed'), 'error'); return r; }
    showToast(r.rewritten ? t('admin.tax.done-rewritten', { n: r.rewritten }) : t(doneKey), 'success');
    if (next !== undefined) _selected = next;
    await load();
    renderEditor();
    renderPane();
    return r;
};

// ── Actions ──────────────────────────────────────────────────────────────────

/** A name not yet taken among `parent`'s children: "New folder", "New folder 2", … */
const freshName = (parent, base) => {
    const taken = new Set(_flat.filter(n => lc(parentOf(n.path)) === lc(parent)).map(n => lc(n.name)));
    if (!taken.has(lc(base))) return base;
    for (let i = 2; ; i++) if (!taken.has(lc(`${base} ${i}`))) return `${base} ${i}`;
};

/**
 * Where New folder / New item put the new node: inside a selected folder, next to a
 * selected item, at the top level with nothing selected — as a file browser does with a
 * folder and a file. A narrower item under an item is made by dragging or Move instead.
 */
const addTarget = () => {
    const n = _selected && _byKey.get(lc(_selected));
    if (!n) return '';
    return n.heading ? n.path : parentOf(n.path);
};

/** New folder or item at addTarget(), then rename it inline. */
const addNode = async (folder) => {
    const parent = addTarget();
    const name = freshName(parent, t(folder ? 'admin.tax.new-folder-name' : 'admin.tax.new-item-name'));
    const path = parent ? `${parent}/${name}` : name;
    if (parent) _collapsed.delete(lc(parent));
    const r = await op({ op: 'add', parent, name, heading: folder ? '1' : '' }, 'admin.tax.added', path);
    if (r.success) startRename(path);
};

const confirmRetag = async (n, from, to) => !n || confirmModal(t('admin.tax.rename-confirm-title'), {
    message: t('admin.tax.rename-confirm-body', { from, to }), confirmLabel: t('admin.tax.rename-confirm-btn'),
});

/** Rename or edit the selected node. `changes` holds only what differs. */
const updateNode = async (path, changes) => {
    const params = { op: 'update', path };
    if (changes.name !== undefined) params.name = changes.name;
    if (changes.heading !== undefined) params.heading = changes.heading ? '1' : '';
    if (changes.description !== undefined) params.description = changes.description;
    if (changes.field !== undefined) { params.field = changes.field; params.multiple = changes.multiple ? '1' : ''; }
    const next = changes.name !== undefined ? (parentOf(path) ? `${parentOf(path)}/${changes.name}` : changes.name) : path;
    if (changes.name !== undefined && !await confirmRetag(inUse(path), path, next)) return;
    await op(params, 'admin.tax.saved', next);
};

const moveNode = async (path, parent) => {
    if (lc(parentOf(path)) === lc(parent)) return;
    const next = parent ? `${parent}/${nameOf(path)}` : nameOf(path);
    const n = inUse(path);
    if (n && !await confirmModal(t('admin.tax.move-confirm-title', { path }), {
        message: t('admin.tax.move-confirm-body', { n, from: path, to: next }),
        confirmLabel: t('admin.tax.move-btn'),
    })) return;
    if (parent) _collapsed.delete(lc(parent));
    await op({ op: 'move', path, parent }, 'admin.tax.moved', next);
};

/** A select of the places `path` may go — never itself or anything below it. */
const pathDialog = async (title, intro, path, { allowTop, okLabel, dangerous = false, allow = () => true }) => {
    const options = _flat
        .filter(n => !under(n.path, path) && allow(n))
        .map(n => `<option value="${esc(n.path)}">${esc('— '.repeat(n.depth) + n.name)}</option>`);
    if (allowTop) options.unshift(`<option value="">${esc(t('admin.tax.top-level'))}</option>`);
    if (!options.length) { showToast(t('admin.tax.no-target'), 'error'); return null; }
    const html = `<div class="tax-dialog"><p>${esc(intro)}</p>
        <select id="tax-f-path" class="form-control">${options.join('')}</select></div>`;
    const ok = await confirmModal(title, { messageHtml: html, confirmLabel: okLabel, dangerous, icon: dangerous ? icons.warning : '' });
    const v = document.getElementById('tax-f-path')?.value;
    return ok ? (v ?? null) : null;
};

const moveSelected = async () => {
    if (!_selected) return;
    const parent = await pathDialog(t('admin.tax.move-title', { path: _selected }), t('admin.tax.move-intro'), _selected,
                                    { allowTop: true, okLabel: t('admin.tax.move-btn'), allow: (x) => mayHold(x.path, _selected) });
    if (parent !== null) await moveNode(_selected, parent);
};

const mergeSelected = async () => {
    if (!_selected) return;
    const path = _selected;
    const target = await pathDialog(t('admin.tax.merge-title', { path }), t('admin.tax.merge-intro', { path }), path,
                                    { allowTop: false, okLabel: t('admin.tax.merge-btn'), dangerous: true,
                                      // Its folders would land inside the target.
                                      allow: (x) => !(x.heading === false && hasChildFolder(path)) });
    if (target) await op({ op: 'merge', path, target }, 'admin.tax.merged', target);
};

const deleteSelected = async () => {
    const n = _selected && _byKey.get(lc(_selected));
    if (!n || inUse(n.path)) return;
    if (!await confirmModal(t('admin.tax.delete-title', { path: n.path }), {
        message: t(n.hasChildren ? 'admin.tax.delete-body-kids' : 'admin.tax.delete-body'),
        confirmLabel: t('admin.tax.delete-btn'), dangerous: true, icon: icons.warning,
    })) return;
    await op({ op: 'delete', path: n.path }, 'admin.tax.deleted', parentOf(n.path) || null);
};

// ── Export / import ──────────────────────────────────────────────────────────

const exportTree = () => {
    const doc = { format: 'astucia-taxonomy', version: 1, exported: new Date().toISOString(), nodes: _data.nodes };
    const blob = new Blob([JSON.stringify(doc, null, 2) + '\n'], { type: 'application/json' });
    const a = el('a');
    a.href = URL.createObjectURL(blob);
    a.download = `taxonomy-${new Date().toISOString().slice(0, 10)}.json`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
};

const importTree = async (file) => {
    const text = await file.text();
    const check = await api.call('admin_taxonomy_import', { space: '', data: text, dry_run: '1' }, 'POST');
    if (!check.success) {
        const list = (check.errors || []).slice(0, 15).map(e => `<li>${esc(e)}</li>`).join('');
        await confirmModal(t('admin.tax.import-invalid-title'), {
            messageHtml: `<div class="tax-dialog"><p>${esc(t('admin.tax.import-invalid-body', { name: file.name }))}</p>`
                       + (list ? `<ul class="tax-errors">${list}</ul>` : '') + '</div>',
            confirmLabel: t('btn.close'), hideCancel: true,
        });
        return;
    }
    const ok = await confirmModal(t('admin.tax.import-confirm-title'), {
        messageHtml: `<div class="tax-dialog"><p><strong>${esc(t('admin.tax.import-warning'))}</strong></p>
            <p>${esc(t('admin.tax.import-summary', { name: file.name, terms: check.terms, current: check.current }))}</p>
            ${check.unclassified ? `<p>${esc(t('admin.tax.import-unclassified', { n: check.unclassified }))}</p>` : ''}
            <p class="form-hint">${esc(t('admin.tax.import-hint'))}</p></div>`,
        confirmLabel: t('admin.tax.import-btn'), dangerous: true, icon: icons.warning,
    });
    if (!ok) return;
    const r = await api.call('admin_taxonomy_import', { space: '', data: text }, 'POST');
    if (!r.success) { showToast(r.message || t('admin.tax.failed'), 'error'); return; }
    showToast(t('admin.tax.imported', { n: r.terms }), 'success');
    _selected = null;
    await load();
    renderEditor();
    renderPane();
};

// ── Tree ─────────────────────────────────────────────────────────────────────

/** The rows currently shown, in order — what the arrow keys walk. */
const visibleRows = () => [...(_ui.tree?.querySelectorAll('.tax-row') || [])];

const select = (path, { focus = false } = {}) => {
    _selected = path;
    for (const r of visibleRows()) {
        const on = path !== null && r.dataset.path === path;
        r.classList.toggle('is-selected', on);
        r.setAttribute('aria-selected', on ? 'true' : 'false');
        if (on) r.scrollIntoView({ block: 'nearest' });
    }
    updateToolbar();
    renderDetails();
    if (focus) _ui.tree?.focus();
};

const toggle = (path, open) => {
    const k = lc(path);
    const want = open ?? _collapsed.has(k);
    if (want) _collapsed.delete(k); else _collapsed.add(k);
    renderTree();
};

const startRename = (path) => {
    const row = visibleRows().find(r => r.dataset.path === path);
    const span = row?.querySelector('.tax-name');
    if (!span) return;
    const input = el('input', 'tax-rename');
    input.type = 'text';
    input.value = nameOf(path);
    span.replaceWith(input);
    input.focus();
    input.select();
    let done = false;
    const finish = async (commit) => {
        if (done) return;
        done = true;
        const name = input.value.trim();
        if (!commit || !name || name === nameOf(path)) { renderTree(); _ui.tree.focus(); return; }
        await updateNode(path, { name });
        _ui.tree?.focus();
    };
    input.addEventListener('keydown', (e) => {
        e.stopPropagation();   // the tree's own keys must not act while typing
        if (e.key === 'Enter') { e.preventDefault(); finish(true); }
        if (e.key === 'Escape') { e.preventDefault(); finish(false); }
    });
    input.addEventListener('blur', () => finish(true));
    input.addEventListener('click', e => e.stopPropagation());
    input.addEventListener('dblclick', e => e.stopPropagation());
};

const renderTree = () => {
    const tree = _ui.tree;
    if (!tree) return;
    tree.innerHTML = '';
    if (!_flat.length) {
        tree.appendChild(el('p', 'tax-empty', t('admin.tax.empty')));
        return;
    }
    const misplaced = new Set(_data.misplaced.map(lc));
    const hidden = new Set();
    for (const n of _flat) {
        const key = lc(n.path);
        const pk = lc(parentOf(n.path));
        if (pk && (hidden.has(pk) || _collapsed.has(pk))) { hidden.add(key); continue; }
        const open = n.hasChildren && !_collapsed.has(key);

        const row = el('div', 'tax-row' + (n.heading ? ' is-folder' : ' is-item'));
        row.dataset.path = n.path;
        row.setAttribute('role', 'treeitem');
        row.setAttribute('aria-level', String(n.depth + 1));
        if (n.hasChildren) row.setAttribute('aria-expanded', open ? 'true' : 'false');
        row.setAttribute('aria-selected', _selected === n.path ? 'true' : 'false');
        if (_selected === n.path) row.classList.add('is-selected');
        if (misplaced.has(key)) { row.classList.add('is-misplaced'); row.title = t('admin.tax.why-folder-in-item'); }
        row.style.paddingLeft = (0.35 + n.depth * 1.1) + 'rem';
        row.draggable = true;
        if (n.description) row.title = n.description;

        const tw = el('span', 'tax-twisty' + (n.hasChildren ? '' : ' is-leaf'), n.hasChildren ? (open ? '▾' : '▸') : '');
        if (n.hasChildren) tw.addEventListener('click', (e) => { e.stopPropagation(); toggle(n.path); });
        row.appendChild(tw);
        const ic = el('span', 'tax-icon');
        ic.innerHTML = n.heading ? (open ? icons.folderOpen : icons.folder) : icons.tag;
        row.appendChild(ic);
        row.appendChild(el('span', 'tax-name', n.name));
        const bound = [..._fields.values()].find(f => lc(f.path) === key);
        if (bound) {
            const chip = el('span', 'tax-field-chip', bound.field + ':');
            chip.title = t('admin.tax.field-chip', { field: bound.field });
            row.appendChild(chip);
        }
        const used = n.heading ? belowUse(n.path) : exactUse(n.path);
        if (used) row.appendChild(el('span', 'tax-count', String(used)));

        row.addEventListener('click', () => select(n.path, { focus: true }));
        row.addEventListener('dblclick', () => { select(n.path); startRename(n.path); });

        // Drag and drop moves. A node can go into any other node except itself and the
        // nodes below it; dropping on the empty part of the tree moves it to the top.
        row.addEventListener('dragstart', (e) => {
            _dragPath = n.path;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', n.path);
            row.classList.add('is-dragging');
        });
        row.addEventListener('dragend', () => {
            _dragPath = null;
            tree.querySelectorAll('.is-drop-target, .is-dragging').forEach(x => x.classList.remove('is-drop-target', 'is-dragging'));
        });
        row.addEventListener('dragover', (e) => {
            if (!_dragPath || under(n.path, _dragPath) || lc(parentOf(_dragPath)) === key) return;
            if (!mayHold(n.path, _dragPath)) return;   // a folder never goes into an item
            e.preventDefault();
            e.stopPropagation();
            e.dataTransfer.dropEffect = 'move';
            row.classList.add('is-drop-target');
        });
        row.addEventListener('dragleave', () => row.classList.remove('is-drop-target'));
        row.addEventListener('drop', (e) => {
            if (!_dragPath || under(n.path, _dragPath) || !mayHold(n.path, _dragPath)) return;
            e.preventDefault();
            e.stopPropagation();
            row.classList.remove('is-drop-target');
            const from = _dragPath;
            _dragPath = null;
            moveNode(from, n.path);
        });
        tree.appendChild(row);
    }
};

// ── Toolbar ──────────────────────────────────────────────────────────────────

const tbBtn = (id, iconHtml, label, fn) => {
    const b = el('button', 'btn btn-sm btn-secondary tax-tb-btn');
    b.type = 'button';
    b.id = id;
    b.innerHTML = iconHtml;
    b.appendChild(el('span', '', label));
    b.addEventListener('click', fn);
    return b;
};

const setState = (b, enabled, why) => {
    b.disabled = !enabled;
    b.title = enabled ? (b.dataset.tip || '') : why;
};

const updateToolbar = () => {
    const tb = _ui.tb;
    if (!tb) return;
    const n = _selected && _byKey.get(lc(_selected));
    const target = addTarget();
    const addTip = !n ? t('admin.tax.add-top-level')
        : n.heading ? t('admin.tax.add-inside', { path: n.path })
        : t(target ? 'admin.tax.add-beside' : 'admin.tax.add-beside-top', { path: n.path });
    tb.addItem.title = addTip;
    tb.addFolder.dataset.tip = addTip;
    // Off only where the new folder would land inside an item.
    setState(tb.addFolder, !isItem(target), t('admin.tax.why-folder-in-item'));
    const why = t('admin.tax.why-select');
    setState(tb.move, !!n, why);
    const others = n ? _flat.some(x => !under(x.path, n.path)) : false;
    setState(tb.merge, !!n && others, n ? t('admin.tax.why-only') : why);
    const used = n ? inUse(n.path) : 0;
    setState(tb.del, !!n && !used, n ? t('admin.tax.why-in-use', { n: used }) : why);
};

// ── Details ──────────────────────────────────────────────────────────────────

const renderDetails = () => {
    const box = _ui.details;
    if (!box) return;
    box.innerHTML = '';
    const n = _selected && _byKey.get(lc(_selected));
    if (!n) { box.appendChild(el('p', 'tax-details-empty', t('admin.tax.d-empty'))); return; }

    const head = el('div', 'tax-details-head');
    const ic = el('span', 'tax-icon');
    ic.innerHTML = n.heading ? icons.folder : icons.tag;
    head.append(ic, el('code', 'tax-details-path', n.path));
    box.appendChild(head);

    // Type
    const typeG = el('div', 'form-group');
    typeG.appendChild(el('span', 'form-label', t('admin.tax.d-type')));
    const typeRow = el('div', 'tax-type-row');
    const radios = {};
    for (const [val, key] of [['folder', 'admin.tax.type-folder'], ['item', 'admin.tax.type-item']]) {
        const lab = el('label', 'tax-type');
        const r = el('input');
        r.type = 'radio';
        r.name = 'tax-d-type';
        r.value = val;
        r.checked = (val === 'folder') === n.heading;
        radios[val] = r;
        lab.append(r, el('span', '', t(key)));
        typeRow.appendChild(lab);
    }
    typeG.appendChild(typeRow);
    // The folder rule, said here rather than as a refusal after Save.
    if (!n.heading && isItem(parentOf(n.path))) {
        radios.folder.disabled = true;
        radios.folder.parentElement.title = t('admin.tax.why-folder-in-item');
    }
    if (n.heading && hasChildFolder(n.path)) {
        radios.item.disabled = true;
        radios.item.parentElement.title = t('admin.tax.why-item-has-folders');
    }
    const boundHere = [..._fields.values()].find(f => lc(f.path) === lc(n.path));
    if (n.heading && boundHere) {
        radios.item.disabled = true;
        radios.item.parentElement.title = t('admin.tax.why-item-bound', { field: boundHere.field });
    }
    const typeHint = el('p', 'form-hint');
    typeG.appendChild(typeHint);
    box.appendChild(typeG);

    // Name, description
    const nameG = el('div', 'form-group');
    nameG.appendChild(el('label', 'form-label', t('admin.tax.f-name')));
    const nameIn = el('input', 'form-control');
    nameIn.id = 'tax-d-name';
    nameIn.value = n.name;
    nameG.appendChild(nameIn);
    box.appendChild(nameG);
    const descG = el('div', 'form-group');
    descG.appendChild(el('label', 'form-label', t('admin.tax.f-desc')));
    const descIn = el('textarea', 'form-control');
    descIn.id = 'tax-d-desc';
    descIn.rows = 3;
    descIn.value = n.description || '';
    descG.appendChild(descIn);
    box.appendChild(descG);

    // Front-matter field bound to this folder: its items become the field's values. Only a
    // folder can be bound (taxonomy.php says why), so an item shows no field section.
    const bound = boundHere;
    const fieldG = el('div', 'form-group' + (n.heading ? '' : ' hidden'));
    fieldG.appendChild(el('label', 'form-label', t('admin.tax.d-field')));
    const fieldIn = el('input', 'form-control');
    fieldIn.id = 'tax-d-field';
    fieldIn.placeholder = t('admin.tax.d-field-ph');
    fieldIn.value = bound ? bound.field : '';
    fieldIn.setAttribute('autocomplete', 'off');
    fieldG.appendChild(fieldIn);
    const multiLab = el('label', 'tax-type tax-multi');
    const multiIn = el('input');
    multiIn.type = 'checkbox';
    multiIn.id = 'tax-d-multiple';
    multiIn.checked = !!bound?.multiple;
    multiLab.append(multiIn, el('span', '', t('admin.tax.d-multiple')));
    fieldG.appendChild(multiLab);
    const fieldHint = el('p', 'form-hint');
    fieldG.appendChild(fieldHint);
    box.appendChild(fieldG);

    // Usage
    box.appendChild(el('p', 'tax-usage', n.heading
        ? t('admin.tax.d-usage-folder', { m: belowUse(n.path) })
        : t('admin.tax.d-usage-item', { n: exactUse(n.path), m: belowUse(n.path) })));
    const asValue = fieldOfValue(n.path);
    if (asValue && !n.heading) {
        const cnt = (_data.fieldCounts[lc(asValue.field)] || {})[lc(n.name)] || 0;
        box.appendChild(el('p', 'tax-usage', t('admin.tax.d-usage-value', { field: asValue.field, value: n.name, n: cnt })));
    }

    const save = el('button', 'btn btn-blue', t('admin.tax.d-save'));
    save.id = 'tax-d-save';
    box.appendChild(save);

    const isFolder = () => radios.folder.checked;
    const refresh = () => {
        typeHint.textContent = t(isFolder() ? 'admin.tax.type-folder-hint' : 'admin.tax.type-item-hint');
        const f = fieldIn.value.trim();
        multiIn.disabled = !f;
        fieldHint.textContent = f ? t('admin.tax.d-field-on', { field: f }) : t('admin.tax.d-field-hint');
        save.disabled = nameIn.value.trim() === n.name && descIn.value.trim() === (n.description || '')
                     && isFolder() === n.heading
                     && f === (bound ? bound.field : '') && (multiIn.checked && !!f) === !!bound?.multiple;
    };
    [nameIn, descIn, fieldIn].forEach(x => x.addEventListener('input', refresh));
    multiIn.addEventListener('change', refresh);
    Object.values(radios).forEach(r => r.addEventListener('change', refresh));
    refresh();

    save.addEventListener('click', async () => {
        const changes = {};
        const name = nameIn.value.trim();
        if (!name) { nameIn.focus(); return; }
        if (name !== n.name) changes.name = name;
        if (descIn.value.trim() !== (n.description || '')) changes.description = descIn.value.trim();
        if (isFolder() !== n.heading) changes.heading = isFolder();
        const f = fieldIn.value.trim();
        if (f !== (bound ? bound.field : '') || (multiIn.checked && !!f) !== !!bound?.multiple) {
            changes.field = f;
            changes.multiple = multiIn.checked && !!f;
        }
        save.disabled = true;
        await updateNode(n.path, changes);
    });
};

// ── Layout ───────────────────────────────────────────────────────────────────

const onTreeKey = (e) => {
    const rows = visibleRows();
    if (!rows.length) return;
    const i = rows.findIndex(r => r.dataset.path === _selected);
    const n = _selected && _byKey.get(lc(_selected));
    const go = (j) => { e.preventDefault(); select(rows[Math.max(0, Math.min(rows.length - 1, j))].dataset.path); };
    switch (e.key) {
        case 'ArrowDown': go(i < 0 ? 0 : i + 1); break;
        case 'ArrowUp':   go(i < 0 ? 0 : i - 1); break;
        case 'Home':      go(0); break;
        case 'End':       go(rows.length - 1); break;
        case 'ArrowRight':
            if (!n) return go(0);
            e.preventDefault();
            if (n.hasChildren && _collapsed.has(lc(n.path))) { toggle(n.path, true); select(n.path); }
            else if (n.hasChildren) go(i + 1);
            break;
        case 'ArrowLeft':
            if (!n) return;
            e.preventDefault();
            if (n.hasChildren && !_collapsed.has(lc(n.path))) { toggle(n.path, false); select(n.path); }
            else if (parentOf(n.path)) select(parentOf(n.path));
            break;
        case 'F2':
            if (n) { e.preventDefault(); startRename(n.path); }
            break;
        case 'Delete':
            if (n && !_ui.tb.del.disabled) { e.preventDefault(); deleteSelected(); }
            break;
    }
};

const renderMisplaced = () => {
    for (const box of [_ui.misplaced, _pane?.misplaced]) {
        if (!box) continue;
        box.classList.toggle('hidden', !_data.misplaced.length);
        box.textContent = _data.misplaced.length
            ? t('admin.tax.misplaced', { n: _data.misplaced.length, paths: _data.misplaced.join(', ') }) : '';
    }
};

const renderEditor = () => {
    renderMisplaced();
    renderTree();
    updateToolbar();
    renderDetails();
};

const renderUnclassified = () => {
    const unc = _pane?.unc;
    if (!unc) return;
    unc.innerHTML = '';
    unc.appendChild(el('h4', '', t('admin.tax.unclassified')));
    if (!_data.unclassified.length) {
        unc.appendChild(el('p', 'pref-hint pref-hint-flush', t('admin.tax.unclassified-none')));
        return;
    }
    unc.appendChild(el('p', 'pref-hint pref-hint-flush', t('admin.tax.unclassified-hint')));
    const chips = el('div', 'tax-chips');
    _data.unclassified.slice(0, 200).forEach(u => chips.appendChild(el('span', 'tag', `${u.tag} · ${u.count}`)));
    unc.appendChild(chips);
    const b = el('button', 'btn btn-sm btn-secondary', t('admin.tax.adopt', { n: _data.unclassified.length }));
    b.addEventListener('click', async () => {
        const r = await api.call('admin_taxonomy_adopt', { space: '' }, 'POST');
        if (!r.success) { showToast(r.message || t('admin.tax.failed'), 'error'); return; }
        showToast(t('admin.tax.adopted', { n: r.added }), 'success');
        await load();
        renderPane();
    });
    unc.appendChild(b);
};

const renderAll = () => {
    const root = document.getElementById('admin-taxonomy-root');
    if (!root) return;
    root.innerHTML = '';

    // Mode
    const modeSec = el('div', 'form-group tax-mode');
    modeSec.appendChild(el('label', 'form-label', t('admin.tax.mode')));
    const row = el('div', 'space-settings-row');
    const sel = el('select', 'form-control');
    sel.id = 'admin-tax-mode';
    ['free', 'suggest', 'controlled'].forEach(m => {
        const o = el('option', '', t('admin.tax.mode-' + m));
        o.value = m;
        sel.appendChild(o);
    });
    sel.value = _data.mode;
    const save = el('button', 'btn btn-blue', t('btn.save'));
    row.append(sel, save);
    modeSec.appendChild(row);
    const hint = el('p', 'pref-hint pref-hint-flush');
    const setHint = () => { hint.textContent = t('admin.tax.mode-' + sel.value + '-hint'); };
    sel.addEventListener('change', setHint);
    setHint();
    modeSec.appendChild(hint);
    save.addEventListener('click', async () => {
        save.disabled = true;
        const r = await api.call('admin_taxonomy_mode', { space: '', mode: sel.value }, 'POST');
        save.disabled = false;
        if (r.success) { _data.mode = r.mode; invalidateTaxonomy(); }
        showToast(r.success ? t('admin.tax.mode-saved') : (r.message || t('admin.tax.failed')), r.success ? 'success' : 'error');
    });
    root.appendChild(modeSec);

    // The editor opens in its own lightbox: a tree with a details panel beside it needs
    // more room than a tab of the admin dialog has.
    const launchRow = el('div', 'tax-launch-row');
    const launch = el('button', 'btn btn-blue', t('admin.tax.launch'));
    launch.id = 'tax-launch';
    launch.type = 'button';
    launch.addEventListener('click', openEditor);
    const summary = el('span', 'tax-summary');
    launchRow.append(launch, summary);
    root.appendChild(launchRow);

    const misplacedBox = el('p', 'tax-misplaced hidden');
    root.appendChild(misplacedBox);
    const unc = el('div', 'tax-unclassified');
    root.appendChild(unc);

    _pane = { summary, misplaced: misplacedBox, unc };
    renderPane();
};

/** What the tab shows outside the editor: counts, misplaced folders, tags not in the tree. */
const renderPane = () => {
    if (!_pane) return;
    const folders = _flat.filter(n => n.heading).length;
    _pane.summary.textContent = t('admin.tax.summary', { folders, items: _flat.length - folders });
    renderMisplaced();
    renderUnclassified();
};

const EDITOR_ID = 'tax-editor-modal';

/** The tree editor, in a lightbox 80% of the window in each direction. */
const openEditor = () => {
    document.getElementById(EDITOR_ID)?.remove();
    const overlay = el('div', 'lightbox-overlay');
    overlay.id = EDITOR_ID;
    const root = el('div', 'lightbox-content tax-editor-content');
    overlay.appendChild(root);
    const header = el('div', 'input-modal-header');
    const hIcon = el('span', 'input-modal-icon');
    hIcon.innerHTML = icons.folder;
    header.append(hIcon, el('h3', '', t('admin.tax.editor-title')));
    root.appendChild(header);

    // Toolbar
    const bar = el('div', 'tax-toolbar');
    bar.setAttribute('role', 'toolbar');
    const tb = {
        addFolder: tbBtn('tax-tb-folder', icons.folder, t('admin.tax.tb-new-folder'), () => addNode(true)),
        addItem:   tbBtn('tax-tb-item',   icons.tag,    t('admin.tax.tb-new-item'),   () => addNode(false)),
        move:      tbBtn('tax-tb-move',   TB_ICONS.move,  t('admin.tax.tb-move'),   moveSelected),
        merge:     tbBtn('tax-tb-merge',  TB_ICONS.merge, t('admin.tax.tb-merge'),  mergeSelected),
        del:       tbBtn('tax-tb-delete', TB_ICONS.del,   t('admin.tax.tb-delete'), deleteSelected),
        expand:    tbBtn('tax-tb-expand', TB_ICONS.expand,   t('admin.tax.tb-expand'),   () => { _collapsed.clear(); renderTree(); }),
        collapse:  tbBtn('tax-tb-collapse', TB_ICONS.collapse, t('admin.tax.tb-collapse'), () => {
            _flat.forEach(n => { if (n.hasChildren) _collapsed.add(lc(n.path)); });
            if (_selected && parentOf(_selected)) _selected = _selected.split('/')[0];
            renderTree(); updateToolbar(); renderDetails();
        }),
    };
    tb.del.classList.add('toolbar-btn-danger');
    const sep = () => el('span', 'tax-tb-sep');
    bar.append(tb.addFolder, tb.addItem, sep(), tb.move, tb.merge, tb.del, sep(), tb.expand, tb.collapse);
    const right = el('span', 'tax-tb-right');
    const file = el('input');
    file.type = 'file';
    file.accept = '.json,application/json';
    file.className = 'hidden';
    file.addEventListener('change', () => { if (file.files[0]) importTree(file.files[0]); file.value = ''; });
    right.append(
        tbBtn('tax-tb-export', '', t('admin.tax.export'), exportTree),
        tbBtn('tax-tb-import', '', t('admin.tax.import'), () => file.click()),
        file);
    bar.appendChild(right);
    root.appendChild(bar);

    // Folders a tree from before the folder rule still has inside an item.
    const misplacedBox = el('p', 'tax-misplaced hidden');
    root.appendChild(misplacedBox);

    // Tree + details
    const split = el('div', 'tax-split');
    const tree = el('div', 'tax-tree');
    tree.id = 'admin-tax-tree';
    tree.tabIndex = 0;
    tree.setAttribute('role', 'tree');
    tree.setAttribute('aria-label', t('admin.tab.taxonomy'));
    tree.addEventListener('keydown', onTreeKey);
    // The empty part of the tree is the top level, for a drop and for clearing the selection.
    tree.addEventListener('dragover', (e) => { if (_dragPath && parentOf(_dragPath)) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; } });
    tree.addEventListener('drop', (e) => {
        if (!_dragPath || !parentOf(_dragPath)) return;
        e.preventDefault();
        const from = _dragPath;
        _dragPath = null;
        moveNode(from, '');
    });
    tree.addEventListener('click', (e) => { if (e.target === tree) select(null); });
    const details = el('div', 'tax-details');
    details.id = 'admin-tax-details';
    split.append(tree, details);
    root.appendChild(split);

    const footer = el('div', 'lightbox-footer');
    const closeBtn = el('button', 'btn btn-secondary', t('btn.close'));
    closeBtn.id = 'tax-editor-close';
    footer.appendChild(closeBtn);
    root.appendChild(footer);

    const close = () => {
        document.removeEventListener('keydown', onKey);
        overlay.remove();
        _ui = {};
        renderPane();   // the tab's counts and lists follow what was done in the editor
    };
    // Escape closes — unless a dialog above the editor or an inline rename has it.
    const onKey = (e) => {
        if (e.key !== 'Escape') return;
        if (!document.getElementById('confirm-modal')?.classList.contains('hidden')) return;
        if (e.target?.classList?.contains('tax-rename')) return;
        close();
    };
    closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    document.body.appendChild(overlay);
    setTimeout(() => document.addEventListener('keydown', onKey), 100);

    _ui = { tree, details, tb, misplaced: misplacedBox };
    renderEditor();
    tree.focus();
};

export const loadTaxonomyPane = async () => {
    if (await load()) renderAll();
};
