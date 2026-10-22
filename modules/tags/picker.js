// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
//
// The tag picker: the whole taxonomy as a tree, with a filter box, in a lightbox 80% of
// the window in each direction. Ticking terms builds the page's new tag list; nothing is
// sent until Apply, so browsing the tree is free.
//
// - Folders are shown but cannot be ticked — they group items and are never tags.
// - The page's tags that are not terms (from before the taxonomy, or free-mode tags the
//   tree has since lost) are listed below the tree so they can still be removed.
// - Typing something that is not a term offers to add it, except in controlled mode;
//   in suggest mode Apply asks before new terms join the tree. The server decides either
//   way — this is so the answer comes before the request rather than after it.
import { getTaxonomy, normalizeTag, lc, sortNodes } from '../core/taxonomy.js';
import { confirmModal } from '../core/utils.js';
import { icons } from '../core/icons.js';
import { t } from '../i18n/index.js';

const OVERLAY_ID = 'tag-picker-modal';

const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
};

const TREE_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="6" height="6" rx="1"/><rect x="15" y="10" width="6" height="5" rx="1"/><rect x="15" y="17" width="6" height="5" rx="1"/><path d="M6 9v10h9"/><path d="M6 12.5h9"/></svg>';

/**
 * @param {object} o
 *   current  string[]                   the page's tags now
 *   onApply  (tags: string[]) => any    called with the new list; may be async
 */
export const openTagPicker = async ({ current, onApply }) => {
    document.getElementById(OVERLAY_ID)?.remove();
    const fetched = await getTaxonomy(true);
    // Folders first, then items, alphabetical — the same order as the editor.
    const tax = { ...fetched, nodes: sortNodes(fetched.nodes) };
    const controlled = tax.mode === 'controlled';

    // Selection: lower-case key → spelling. Map keeps insertion order, which is the order
    // Apply sends: the page's existing tags first, as they were, then additions.
    const selected = new Map(current.map(x => [lc(x), x]));
    const termKeys = new Set();
    const walkKeys = (nodes, parent) => (nodes || []).forEach(n => {
        const p = parent ? `${parent}/${n.name}` : n.name;
        termKeys.add(lc(p));
        walkKeys(n.children, p);
    });
    walkKeys(tax.nodes, '');
    const extras = new Map(current.filter(x => !termKeys.has(lc(x))).map(x => [lc(x), x]));

    const overlay = el('div', 'lightbox-overlay');
    overlay.id = OVERLAY_ID;
    const box = el('div', 'lightbox-content tag-picker-content');
    overlay.appendChild(box);

    const header = el('div', 'input-modal-header');
    const hIcon = el('span', 'input-modal-icon');
    hIcon.innerHTML = TREE_ICON;
    header.appendChild(hIcon);
    header.appendChild(el('h3', '', t('tags.picker-title')));
    box.appendChild(header);

    const search = el('input', 'form-control tag-picker-search');
    search.type = 'text';
    search.placeholder = t(controlled ? 'tags.picker-search-controlled' : 'tags.picker-search');
    search.setAttribute('autocomplete', 'off');
    box.appendChild(search);

    const body = el('div', 'tag-picker-body');
    box.appendChild(body);

    const footer = el('div', 'lightbox-footer tag-picker-footer');
    const count = el('span', 'tag-picker-count');
    const cancelBtn = el('button', 'btn btn-secondary', t('btn.cancel'));
    const applyBtn  = el('button', 'btn btn-blue', t('tags.picker-apply'));
    footer.append(count, cancelBtn, applyBtn);
    box.appendChild(footer);

    const expanded = new Set();
    // Open the top level, and the branches leading to what the page already carries.
    (tax.nodes || []).forEach(n => expanded.add(lc(n.name)));
    for (const k of selected.keys()) {
        const segs = k.split('/');
        for (let i = 1; i < segs.length; i++) expanded.add(segs.slice(0, i).join('/'));
    }

    const updateCount = () => { count.textContent = t('tags.picker-count', { n: selected.size }); };

    const toggle = (key, spelled, on) => {
        if (on) selected.set(key, spelled); else selected.delete(key);
        updateCount();
    };

    const checkRow = (key, spelled, label, countN, desc, cls = '') => {
        const row = el('label', 'tag-picker-row ' + cls);
        const cb = el('input');
        cb.type = 'checkbox';
        cb.checked = selected.has(key);
        cb.dataset.tag = spelled;
        cb.addEventListener('change', () => toggle(key, spelled, cb.checked));
        row.appendChild(cb);
        const ic = el('span', 'tag-picker-icon');
        ic.innerHTML = icons.tag;
        row.appendChild(ic);
        row.appendChild(el('span', 'tag-picker-name', label));
        if (countN) row.appendChild(el('span', 'tag-picker-n', String(countN)));
        if (desc) { row.appendChild(el('span', 'tag-picker-desc', desc)); row.title = desc; }
        return row;
    };

    const render = () => {
        const q = lc(search.value.trim());
        body.innerHTML = '';

        // Offer the typed text as a new tag when it is not a term and the mode allows it.
        const typed = normalizeTag(search.value);
        if (typed && !controlled && !termKeys.has(lc(typed)) && !selected.has(lc(typed))) {
            const add = el('button', 'btn btn-sm btn-secondary tag-picker-add', t('tags.picker-add-new', { tag: typed }));
            add.addEventListener('click', () => {
                extras.set(lc(typed), typed);
                toggle(lc(typed), typed, true);
                search.value = '';
                render();
                search.focus();
            });
            body.appendChild(add);
        }

        // Which nodes show: all, or — when filtering — matches and their ancestors.
        const visible = new Set();
        const mark = (nodes, parent) => {
            let any = false;
            for (const n of nodes || []) {
                const p = parent ? `${parent}/${n.name}` : n.name;
                const kids = mark(n.children, p);
                if (!q || lc(p).includes(q) || kids) { visible.add(lc(p)); any = true; }
            }
            return any;
        };
        mark(tax.nodes, '');

        const tree = el('ul', 'tag-picker-tree');
        const build = (nodes, parent, ul) => {
            for (const n of nodes || []) {
                const p = parent ? `${parent}/${n.name}` : n.name;
                const key = lc(p);
                if (!visible.has(key)) continue;
                const li = el('li');
                const line = el('div', 'tag-picker-line');
                const hasKids = !!(n.children && n.children.length);
                const open = q ? true : expanded.has(key);
                const tw = el('button', 'tag-picker-twisty' + (hasKids ? '' : ' is-leaf'), hasKids ? (open ? '▾' : '▸') : '');
                tw.type = 'button';
                tw.tabIndex = -1;
                if (hasKids) tw.addEventListener('click', () => {
                    if (expanded.has(key)) expanded.delete(key); else expanded.add(key);
                    render();
                });
                line.appendChild(tw);
                if (n.heading) {
                    // A folder: groups items, never ticked — the same icon as in the editor.
                    const h = el('span', 'tag-picker-heading');
                    const ic = el('span', 'tag-picker-icon');
                    ic.innerHTML = open && hasKids ? icons.folderOpen : icons.folder;
                    h.append(ic, el('span', 'tag-picker-heading-name', n.name));
                    if (n.description) h.title = n.description;
                    line.appendChild(h);
                } else {
                    line.appendChild(checkRow(key, p, n.name, tax.counts[key], n.description));
                }
                li.appendChild(line);
                if (hasKids && open) {
                    const sub = el('ul');
                    build(n.children, p, sub);
                    li.appendChild(sub);
                }
                ul.appendChild(li);
            }
        };
        build(tax.nodes, '', tree);
        if (tree.children.length) body.appendChild(tree);
        else if (!(tax.nodes || []).length) body.appendChild(el('p', 'tag-picker-empty', t('tags.picker-empty-tree')));
        else if (q) body.appendChild(el('p', 'tag-picker-empty', t('tags.picker-no-match')));

        // The page's tags that are not terms, and new ones added above.
        const others = [...extras.values()].filter(x => !q || lc(x).includes(q));
        if (others.length) {
            const sec = el('div', 'tag-picker-extras');
            sec.appendChild(el('h4', '', t('tags.picker-not-terms')));
            others.forEach(x => sec.appendChild(checkRow(lc(x), x, x, tax.counts[lc(x)], '', 'is-extra')));
            body.appendChild(sec);
        }
    };

    const close = () => {
        document.removeEventListener('keydown', onKey, true);
        overlay.remove();
    };
    const onKey = (e) => {
        if (e.key !== 'Escape') return;
        // The suggest-mode question sits above the picker; Escape belongs to it then.
        if (!document.getElementById('confirm-modal')?.classList.contains('hidden')) return;
        e.stopPropagation();
        close();
    };

    // Enter in the filter: tick the first matching term; only when no term matches does
    // it add the typed text as a new tag — a typo must not quietly become a term.
    search.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const first = body.querySelector('.tag-picker-tree input[type=checkbox]');
        if (first) { first.click(); return; }
        body.querySelector('.tag-picker-add')?.click();
    });
    search.addEventListener('input', render);

    cancelBtn.addEventListener('click', close);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

    applyBtn.addEventListener('click', async () => {
        const next = [...selected.values()];
        const unchanged = next.length === current.length && next.every((x, i) => x === current[i]);
        if (unchanged) { close(); return; }
        const fresh = next.filter(x => !termKeys.has(lc(x)) && !current.some(c => lc(c) === lc(x)));
        if (tax.mode === 'suggest' && fresh.length && !await confirmModal(t('tags.suggest-title'), {
            message: t('tags.suggest-body-many', { tags: fresh.join(', ') }),
            confirmLabel: t('tags.suggest-add'),
        })) return;
        applyBtn.disabled = true;
        const ok = await onApply(next);
        applyBtn.disabled = false;
        if (ok !== false) close();
    });

    document.body.appendChild(overlay);
    document.addEventListener('keydown', onKey, true);
    render();
    updateCount();
    setTimeout(() => search.focus(), 30);
};
