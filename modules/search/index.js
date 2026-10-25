// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { hidePageControls } from '../core/page_chrome.js';
import { loadPage } from '../page_view/index.js';
import { revealAndSelectFile, refreshFileTree } from '../file_tree/index.js';
import { t } from '../i18n/index.js';
import { lc, tagLeaf } from '../core/taxonomy.js';
import { updateFavoriteBtn } from '../nav/index.js';

// The cloud shows the TAG_CLOUD_TOP most-used entries until expanded, and only the
// tags matching the sidebar search box while something is typed in it. Both are
// client-side over one fetch, so typing never costs a request.
//
// Nested tags (`Department/Finance`, the taxonomy's convention) are grouped: the top level
// shows one chip per first segment, counted in distinct pages by the server (`branches`),
// and its caret opens the next level in place. Grouping reads the tag strings rather than
// the taxonomy tree, so it works in every mode and with tags the tree does not hold.
// Clicking a branch's name lists everything under it — get_pages_by_tag already matches
// descendants. While filtering, the list is flat and matches on the full path, so typing
// `budget` finds `Department/Finance/Budget` without opening anything. Every chip shows
// only a tag's last segment; the full path is its tooltip.
const TAG_CLOUD_TOP = 40;
let _tagData     = [];
let _tagTree     = [];
let _tagFilter   = '';
let _tagExpanded = false;
const _tagOpen   = new Set();   // lower-cased paths of the branches opened

const byName = (a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });

const buildTagTree = (tags, branches) => {
    const pages = new Map((branches || []).map(b => [lc(b.tag), b.pages]));
    const root = new Map();
    tags.forEach(({ tag, count }) => {
        let level = root, path = '', node = null;
        String(tag).split('/').forEach(seg => {
            path = path ? path + '/' + seg : seg;
            const key = lc(seg);
            node = level.get(key);
            if (!node) {
                node = { name: seg, path, count: 0, children: new Map() };
                level.set(key, node);
            }
            level = node.children;
        });
        node.count += count;
    });
    const finish = (level) => [...level.values()].map(n => {
        const children = finish(n.children);
        return { ...n, children,
                 weight: children.length ? (pages.get(lc(n.path)) ?? n.count) : n.count };
    }).sort(byName);
    return finish(root);
};

const tagChip = (tag, label, count) => {
    const el = document.createElement('span');
    el.className = 'tag-cloud-item';
    el.textContent = `${label} (${count})`;
    el.dataset.tag = tag;
    if (label !== tag) el.title = tag;
    return el;
};

const renderTagNode = (node, into) => {
    if (!node.children.length) {
        into.appendChild(tagChip(node.path, node.name, node.weight));
        return;
    }
    const open = _tagOpen.has(lc(node.path));
    const chip = tagChip(node.path, node.name, node.weight);
    chip.classList.add('tag-cloud-branch');
    const caret = document.createElement('button');
    caret.type = 'button';
    caret.className = 'tag-cloud-caret';
    caret.dataset.branch = lc(node.path);
    caret.textContent = open ? '▾' : '▸';
    caret.setAttribute('aria-expanded', open ? 'true' : 'false');
    caret.title = open ? t('search.tags-collapse') : t('search.tags-expand');
    caret.setAttribute('aria-label', caret.title);
    chip.prepend(caret);
    if (!open) { into.appendChild(chip); return; }

    const group = document.createElement('div');
    group.className = 'tag-cloud-group';
    group.appendChild(chip);
    const kids = document.createElement('div');
    kids.className = 'tag-cloud-children';
    node.children.forEach(child => renderTagNode(child, kids));
    group.appendChild(kids);
    into.appendChild(group);
};

const renderTagCloud = () => {
    const tagCloud = document.getElementById('tag-cloud');
    if (!tagCloud) return;
    tagCloud.innerHTML = '';

    const filter = _tagFilter.trim().toLowerCase();
    if (filter) {
        const shown = _tagData.filter(({ tag }) => tag.toLowerCase().includes(filter));
        shown.forEach(({ tag, count }) => tagCloud.appendChild(tagChip(tag, tagLeaf(tag), count)));
        if (!shown.length && _tagData.length) {
            const empty = document.createElement('span');
            empty.className = 'tag-cloud-empty';
            empty.textContent = t('search.tags-none');
            tagCloud.appendChild(empty);
        }
        return;
    }

    let shown = _tagTree;
    if (!_tagExpanded && _tagTree.length > TAG_CLOUD_TOP) {
        // Pick by count, but keep alphabetical order for scanning.
        const top = new Set([..._tagTree].sort((a, b) => b.weight - a.weight)
            .slice(0, TAG_CLOUD_TOP));
        shown = _tagTree.filter(n => top.has(n));
    }
    shown.forEach(node => renderTagNode(node, tagCloud));

    if (_tagTree.length > TAG_CLOUD_TOP) {
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'tag-cloud-toggle';
        toggle.textContent = _tagExpanded
            ? t('search.tags-show-fewer')
            : t('search.tags-show-all', { count: _tagTree.length });
        tagCloud.appendChild(toggle);
    }
};

export const generateTagCloud = async () => {
    const result = await api.call('get_tag_cloud');
    _tagData = result.data || [];
    _tagTree = buildTagTree(_tagData, result.branches);
    renderTagCloud();
};

const RESULTS_PER_PAGE = 50;
let _results    = [];
let _showSpace  = false;
let _title      = '';
let _curPage    = 0;

const PAGE_TYPE = (path) => {
    if (path.endsWith('.drawio')) return { label: 'diagram', cls: 'sr-type-diagram' };
    if (path.endsWith('.list'))   return { label: 'list',    cls: 'sr-type-list'    };
    if (path.endsWith('.chat'))   return { label: 'chat',    cls: 'sr-type-chat'    };
    return { label: 'page', cls: 'sr-type-page' };
};

const fmtDate = (ts) => {
    if (!ts) return null;
    const diff = Math.floor(Date.now() / 1000) - ts;
    if (diff < 60)          return 'just now';
    if (diff < 3600)        return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400)       return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 7 * 86400)   return `${Math.floor(diff / 86400)}d ago`;
    return new Date(ts * 1000).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
};

const escHtml = (s) => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

/**
 * One result row. Exported because the My Mentions / My Comments dialog renders the
 * same rows in a lightbox — the same list of pages deserves the same card, and a second
 * copy would drift the first time one of them gained a badge (`is_new` already is one).
 */
export const buildResultCard = (page, showSpace) => {
    const segments   = page.path.replace(/\.(md|drawio|list|chat)$/, '').split('/');
    const name       = segments.pop();
    const folderPath = segments.join(' / ');
    const type       = PAGE_TYPE(page.path);
    const heading    = (page.header || '').replace(/^#+\s*/, '').trim();

    const tagsHtml = (page.tags || []).length
        ? `<span class="sr-tags">${page.tags.map(t => `<span class="sr-tag">${escHtml(t)}</span>`).join('')}</span>`
        : '';

    const metaParts = [];
    if (page.created) {
        const who = page.createdBy?.name ? ` by ${escHtml(page.createdBy.name)}` : '';
        metaParts.push(`<span class="sr-meta-item">Created ${fmtDate(page.created)}${who}</span>`);
    }
    if (page.updated && page.updated !== page.created) {
        const who = page.updatedBy?.name ? ` by ${escHtml(page.updatedBy.name)}` : '';
        metaParts.push(`<span class="sr-meta-item">Updated ${fmtDate(page.updated)}${who}</span>`);
    }
    const metaHtml = metaParts.length ? `<div class="sr-meta">${metaParts.join('<span class="sr-meta-sep">·</span>')}${tagsHtml}</div>` : (tagsHtml ? `<div class="sr-meta">${tagsHtml}</div>` : '');

    const spaceBadge = (showSpace && page.space)
        ? `<span class="sr-space-badge">${escHtml(page.space)}</span>` : '';
    // Set only by the mentions scan, so every other caller renders exactly as before.
    const newBadge = page.is_new ? `<span class="sr-new-badge">${escHtml(t('mentions.new'))}</span>` : '';

    return `
        <div class="sr-card">
            <div class="sr-card-top">
                <a href="#" class="sr-title search-result-link" data-id="${page.id}" data-space="${escHtml(page.space || '')}">${escHtml(name)}</a>
                ${newBadge}${spaceBadge}<span class="sr-type-badge ${type.cls}">${type.label}</span>
            </div>
            ${folderPath ? `<div class="sr-path">${escHtml(folderPath)}</div>` : ''}
            ${heading    ? `<div class="sr-heading">${escHtml(heading)}</div>` : ''}
            ${page.preview ? `<div class="sr-preview">${page.preview}</div>` : ''}
            ${metaHtml}
        </div>`;
};

const renderPage = (page) => {
    _curPage = page;
    const total      = _results.length;
    const totalPages = Math.ceil(total / RESULTS_PER_PAGE) || 1;
    const start      = page * RESULTS_PER_PAGE;
    const slice      = _results.slice(start, start + RESULTS_PER_PAGE);

    const pageLabel = totalPages > 1 ? ` — ${t('search.page-of', { page: page + 1, total: totalPages })}` : '';
    document.getElementById('current-page-title').textContent = `${_title} (${total})${pageLabel}`;

    let html = `<div class="search-results">`;
    if (total === 0) {
        html += `<div class="sr-empty">${t('search.no-results')}</div>`;
    } else {
        slice.forEach(p => { html += buildResultCard(p, _showSpace); });
        if (totalPages > 1) {
            html += `<div class="sr-pagination">
                <button class="sr-pag-btn" data-pag="prev"${page === 0 ? ' disabled' : ''}>${t('search.prev')}</button>
                <span class="sr-pag-info">${t('search.page-of', { page: page + 1, total: totalPages })}</span>
                <button class="sr-pag-btn" data-pag="next"${page >= totalPages - 1 ? ' disabled' : ''}>${t('search.next')}</button>
            </div>`;
        }
    }
    html += `</div>`;
    document.getElementById('viewer-content').innerHTML = html;
    document.getElementById('viewer-content').scrollTop = 0;
};

export const displaySearchResults = (title, results, showSpace = false) => {
    state.currentPagePath = null;
    state.currentPageId = null;
    _results   = results;
    _showSpace = showSpace;
    _title     = title;

    document.getElementById('page-id-display').classList.add('hidden');
    document.getElementById('frontmatter-badge')?.classList.add('hidden');
    document.getElementById('edit-btn').disabled = true;
    document.getElementById('viewer-container').classList.remove('hidden');
    document.getElementById('list-view-container').classList.add('hidden');
    document.getElementById('chat-view-container').classList.add('hidden');
    document.getElementById('diagram-viewer').classList.add('hidden');
    // The listing pane is a sibling of the viewer, so results rendered while a folder or
    // a files library is open would appear underneath it, toolbar and all.
    document.getElementById('files-folder-container')?.classList.add('hidden');
    document.getElementById('viewer-content').classList.remove('hidden');

    // Everything that belongs to the page you were on. One shared list rather than the
    // subset this function happened to know about — which was missing the chat settings
    // gear and the knowledge-graph button, so a results list opened carrying both.
    hidePageControls();
    document.getElementById('page-actions-group').classList.add('hidden');
    document.querySelector('.editor-container-wrapper').classList.add('hidden');
    document.getElementById('viewer-container').classList.remove('hidden');
    // A results list is not a page, so there is nothing to star. Left alone the button
    // stays visible from whatever page was open before, still lit for *that* page, and
    // clicking it does nothing — currentPageId is null by the line above. Goes through
    // nav rather than the DOM so the star has one owner.
    updateFavoriteBtn(null);

    renderPage(0);
};

const performSearch = async () => {
    const query = document.getElementById('search-query-input').value.trim();
    if (!query) return;
    const allSpaces = document.getElementById('search-all-spaces-chk')?.checked;
    const params = { query };
    if (allSpaces) params.all_spaces = '1';
    const result = await api.call('search', params);
    if (result.success) {
        displaySearchResults(t('search.results', { query }), result.data, !!result.cross_space);
    }
};

export const init = () => {
    const tagCloud = document.getElementById('tag-cloud');
    const searchQueryInput = document.getElementById('search-query-input');
    const searchQueryBtn = document.getElementById('search-query-btn');
    const viewerContent = document.getElementById('viewer-content');

    // Show "Search all spaces" toggle only when SQLite engine is active.
    if (window.WIKI_SEARCH_ENGINE === 'sqlite') {
        document.getElementById('search-all-spaces-row')?.classList.remove('hidden');
    }

    tagCloud.addEventListener('click', async (e) => {
        if (e.target.classList.contains('tag-cloud-toggle')) {
            _tagExpanded = !_tagExpanded;
            renderTagCloud();
            return;
        }
        const caret = e.target.closest('.tag-cloud-caret');
        if (caret) {
            const key = caret.dataset.branch;
            if (_tagOpen.has(key)) _tagOpen.delete(key); else _tagOpen.add(key);
            renderTagCloud();
            return;
        }
        const chip = e.target.closest('.tag-cloud-item');
        if (chip) {
            const tag = chip.dataset.tag;
            const result = await api.call('get_pages_by_tag', { tag, all_spaces: '1' });
            if (result.success) displaySearchResults(t('search.tag-results', { tag }), result.data, !!result.cross_space);
        }
    });

    searchQueryBtn.addEventListener('click', performSearch);
    searchQueryInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') performSearch(); });
    // Typing narrows the tag cloud; Enter still runs the full-text search. Only real
    // typing counts — a search started elsewhere fills the box without filtering.
    searchQueryInput.addEventListener('input', () => {
        _tagFilter = searchQueryInput.value;
        renderTagCloud();
    });

    // Click on a search result link — handles same-space and cross-space navigation.
    viewerContent.addEventListener('click', async (e) => {
        const pagBtn = e.target.closest('[data-pag]');
        if (pagBtn) {
            const totalPages = Math.ceil(_results.length / RESULTS_PER_PAGE) || 1;
            if (pagBtn.dataset.pag === 'prev' && _curPage > 0) renderPage(_curPage - 1);
            else if (pagBtn.dataset.pag === 'next' && _curPage < totalPages - 1) renderPage(_curPage + 1);
            return;
        }

        const link = e.target.closest('.search-result-link');
        if (!link) return;
        e.preventDefault();
        await openResult(link.dataset.id, link.dataset.space || null);
    });
};

/**
 * Open a result by page id, from wherever it was listed.
 *
 * Exported for the mentions dialog, which lists the same cards in a lightbox. It is more
 * than a loadPage() call — the target can be in another Space, which has to be switched
 * to and its tree loaded before the page's tags can be found — so a second copy in the
 * dialog would be the interesting half of this module written twice.
 */
export const openResult = async (pageId, linkSpace = null) => {
    if (!pageId) return;
    // Pass the result's space so get_path_from_id uses the right indexer.
    const getParams = { pageid: pageId };
    if (linkSpace && linkSpace !== state.currentSpace) getParams.space = linkSpace;
    const result = await api.call('get_path_from_id', getParams);
    if (!result.success || !result.path) return;

    const targetSpace = result.space || (linkSpace !== state.currentSpace ? linkSpace : null);
    if (targetSpace && targetSpace !== state.currentSpace) {
        const { switchSpaceSilently } = await import('../spaces/index.js');
        switchSpaceSilently(targetSpace);
        await refreshFileTree();
    }

    const findItem = (items, path) => {
        for (const item of items) {
            if (item.path === path) return item;
            if (item.children) { const found = findItem(item.children, path); if (found) return found; }
        }
        return null;
    };
    const item = findItem(state.fullFileTree, result.path);
    document.querySelector('.pane-tab[data-pane="pages"]').click();
    await loadPage(result.path, pageId, item?.tags || []);
    revealAndSelectFile(result.path);
    history.pushState({ pageId }, '', `?pageid=${pageId}`);
};
