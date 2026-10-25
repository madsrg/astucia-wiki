// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { refreshFileTree, revealAndSelectFile, treeEntry } from '../file_tree/index.js';
import { showToast, confirmModal } from '../core/utils.js';
import { t } from '../i18n/index.js';
import { getTaxonomy, invalidateTaxonomy, indexByPath, flatten, normalizeTag, lc, tagLeaf } from '../core/taxonomy.js';
import { openTagPicker } from './picker.js';

// Session-level cache of all tags across all accessible spaces.
let _allTags = null;
let _fetchPromise = null;

const fetchAllTags = async () => {
    if (_allTags !== null) return _allTags;
    if (_fetchPromise) return _fetchPromise;
    // Pass space:'' to skip space routing — this is a cross-space call.
    _fetchPromise = api.call('get_all_tags', { space: '' }).then(r => {
        _fetchPromise = null;
        if (r.success && Array.isArray(r.data)) {
            _allTags = r.data; // cache only on clean success
        }
        return _allTags ?? r.data ?? [];
    }).catch(() => {
        _fetchPromise = null;
        return _allTags ?? []; // return cached value if available, else empty
    });
    return _fetchPromise;
};

// Call this after adding/removing a tag so the local cache stays warm.
const cacheTag = (tag) => {
    if (_allTags && !_allTags.includes(tag)) {
        _allTags.push(tag);
        _allTags.sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base' }));
    }
};

// ── Render ────────────────────────────────────────────────────────────────────

export const renderTags = () => {
    renderFmNotice();
    const tagsDisplay = document.getElementById('tags-display');
    tagsDisplay.innerHTML = '';
    state.currentPageTags.forEach((tag, index) => {
        const tagEl = document.createElement('span');
        tagEl.className = 'tag';
        // A nested tag shows its last segment; the full path is the tooltip.
        tagEl.textContent = tagLeaf(tag);
        if (tagLeaf(tag) !== tag) tagEl.title = tag;
        const removeBtn = document.createElement('button');
        removeBtn.className = 'tag-remove';
        removeBtn.textContent = '×';
        removeBtn.onclick = () => removeTag(index);
        tagEl.appendChild(removeBtn);
        tagsDisplay.appendChild(tagEl);
    });
};

// ── Tag CRUD ──────────────────────────────────────────────────────────────────

/**
 * Store a page's whole tag list. The server applies the taxonomy — it may respell a tag
 * or refuse one — so what it returns is what the page shows, success or not.
 */
const setPageTags = async (next) => {
    if (!state.currentPageId) return false;
    const path = state.currentPagePath;
    const result = await api.call('update_tags', { id: state.currentPageId, tags: JSON.stringify(next) }, 'POST');
    if (Array.isArray(result.tags)) state.currentPageTags = result.tags;
    await followFileWrite(path, result);
    renderTags();
    if (!result.success) {
        if (result.message) showToast(result.message, 'error');
        return false;
    }
    // A new tag may have grown the tree, and the autocomplete list learns it too.
    invalidateTaxonomy();
    state.currentPageTags.forEach(cacheTag);
    await refreshFileTree();
    if (state.currentPagePath) revealAndSelectFile(state.currentPagePath);
    return true;
};

/**
 * Check a typed tag against the taxonomy before sending it, so the common refusals are
 * explained here rather than as a server error: a heading is never a tag, controlled mode
 * takes only terms, and suggest mode asks before a new term joins the tree.
 * Returns the tag as the tree spells it, or null.
 */
const vetNewTag = async (raw) => {
    const tag = normalizeTag(raw);
    if (!tag) return null;
    const tax = await getTaxonomy();
    const node = indexByPath(tax.nodes).get(lc(tag));
    if (node) {
        if (node.heading) { showToast(t('tags.is-heading', { tag: node.path }), 'error'); return null; }
        return node.path;
    }
    if (state.currentPageTags.some(x => lc(x) === lc(tag))) return tag;
    if (tax.mode === 'controlled') { showToast(t('tags.not-a-term', { tag }), 'error'); return null; }
    if (tax.mode === 'suggest' && !await confirmModal(t('tags.suggest-title'), {
        message: t('tags.suggest-body', { tag }), confirmLabel: t('tags.suggest-add'),
    })) return null;
    return tag;
};

const addTag = async (raw) => {
    document.getElementById('tag-input').value = '';
    const tag = await vetNewTag(raw);
    if (!tag || state.currentPageTags.some(x => lc(x) === lc(tag))) return;
    await setPageTags([...state.currentPageTags, tag]);
};

const removeTag = async (index) => {
    await setPageTags(state.currentPageTags.filter((_, i) => i !== index));
};

// ── Front-matter sync ─────────────────────────────────────────────────────────
//
// In a Space that keeps `tags:` in step (Space settings → Content), every tag change also
// writes the page's block — the server does it, as a delta. What it cannot do on its own is
// settle a page whose block and tags already disagree: an imported Obsidian note, a block
// edited outside the wiki, a page from before the setting. Those are put to the person as
// a notice here, each direction separately, with Ignore remembered per page until the
// difference changes.

/** The file was rewritten by a sync: tell the open-page watcher and the Metadata panel. */
const followFileWrite = async (path, res) => {
    if (!res || res.size === undefined || state.currentPagePath !== path) return;
    const { rebaselineFileWatch, updateFrontmatterBadge } = await import('../page_view/index.js');
    rebaselineFileWatch(path, res.lastUpdated, res.size);
    if (res.frontmatter !== undefined) {
        state.currentPageFrontmatter = res.frontmatter ?? null;
        state.currentPageFrontmatterNested = res.frontmatter_nested ?? null;
        state.currentPageFrontmatterManaged = res.frontmatter_managed ?? null;
        updateFrontmatterBadge();
    }
};

/** The block's tags, or null when it has no `tags` key. Mirrors wiki_fm_tags_of(). */
const blockTags = (fm) => {
    if (!fm) return null;
    const key = Object.keys(fm).find(k => k.toLowerCase() === 'tags');
    if (key === undefined) return null;
    const v = fm[key];
    return (Array.isArray(v) ? v : String(v ?? '').split(','))
        .map(x => String(x).trim().replace(/^#/, '')).filter(Boolean);
};

const ignoreKey = () => `wikiFmTagsIgnore:${state.currentSpace}:${state.currentPageId}`;

const renderFmNotice = () => {
    const container = document.getElementById('tags-container');
    if (!container) return;
    let box = document.getElementById('tags-fm-notice');
    if (!box) {
        box = document.createElement('div');
        box.id = 'tags-fm-notice';
        box.className = 'tags-fm-notice hidden';
        container.appendChild(box);
    }
    box.classList.add('hidden');
    box.innerHTML = '';
    if (!state.spaceFmTagsSync || state.spaceReadOnly || window.WIKI_ROLE === 'reader'
        || state.currentPageType !== 'md' || !state.currentPageId) return;

    const fm = blockTags(state.currentPageFrontmatter) ?? [];
    const page = state.currentPageTags || [];
    const fmOnly  = fm.filter(x => !page.some(p => lc(p) === lc(x)));
    const pageOnly = page.filter(x => !fm.some(f => lc(f) === lc(x)));
    if (!fmOnly.length && !pageOnly.length) return;
    const sig = JSON.stringify([fmOnly.map(lc).sort(), pageOnly.map(lc).sort()]);
    try { if (localStorage.getItem(ignoreKey()) === sig) return; } catch {}

    const line = (text, actionLabel, action) => {
        const row = document.createElement('div');
        row.className = 'tags-fm-line';
        const msg = document.createElement('span');
        msg.textContent = text;
        row.appendChild(msg);
        const btn = document.createElement('button');
        btn.className = 'btn btn-sm btn-secondary';
        btn.textContent = actionLabel;
        btn.addEventListener('click', async () => { btn.disabled = true; await action(); btn.disabled = false; });
        row.appendChild(btn);
        box.appendChild(row);
    };
    if (fmOnly.length) {
        line(t('tags.fm-only', { tags: fmOnly.join(', ') }), t('tags.fm-add'), async () => {
            const tax = await getTaxonomy();
            const terms = indexByPath(tax.nodes);
            const fresh = fmOnly.filter(x => !terms.has(lc(normalizeTag(x))));
            if (fresh.length && tax.mode === 'suggest' && !await confirmModal(t('tags.suggest-title'), {
                message: t('tags.suggest-body-many', { tags: fresh.join(', ') }), confirmLabel: t('tags.suggest-add'),
            })) return;
            await setPageTags([...page, ...fmOnly]);
        });
    }
    if (pageOnly.length) {
        line(t('tags.page-only', { tags: pageOnly.join(', ') }), t('tags.fm-write'), async () => {
            const path = state.currentPagePath;
            const res = await api.call('sync_fm_tags', { file: path }, 'POST');
            if (!res.success) { showToast(res.message || t('tags.fm-write-failed'), 'error'); return; }
            await followFileWrite(path, res);
            renderFmNotice();
        });
    }
    const ignore = document.createElement('button');
    ignore.className = 'btn btn-sm btn-link tags-fm-ignore';
    ignore.textContent = t('tags.fm-ignore');
    ignore.addEventListener('click', () => {
        try { localStorage.setItem(ignoreKey(), sig); } catch {}
        box.classList.add('hidden');
    });
    box.appendChild(ignore);
    box.classList.remove('hidden');
};

// ── Autocomplete ──────────────────────────────────────────────────────────────

let _suggestions = [];
let _activeIdx   = -1;

const buildDropdown = () => {
    const existing = document.getElementById('tag-suggestions');
    if (existing) return existing;
    const ul = document.createElement('ul');
    ul.id = 'tag-suggestions';
    ul.className = 'tag-suggestions hidden';
    document.querySelector('.tag-input-wrap').appendChild(ul);
    return ul;
};

const showSuggestions = (matches) => {
    const ul = buildDropdown();
    _suggestions = matches;
    _activeIdx   = -1;
    if (!matches.length) { ul.classList.add('hidden'); return; }

    ul.innerHTML = '';
    matches.forEach((tag, i) => {
        const li = document.createElement('li');
        li.className = 'tag-suggestion-item';
        li.textContent = tag;
        li.addEventListener('mousedown', (e) => {
            e.preventDefault(); // keep input focused
            selectSuggestion(tag);
        });
        li.addEventListener('mouseover', () => {
            setActive(i);
        });
        ul.appendChild(li);
    });
    ul.classList.remove('hidden');
};

const hideSuggestions = () => {
    const ul = document.getElementById('tag-suggestions');
    if (ul) ul.classList.add('hidden');
    _suggestions = [];
    _activeIdx   = -1;
};

const setActive = (idx) => {
    const ul = document.getElementById('tag-suggestions');
    if (!ul) return;
    const items = ul.querySelectorAll('.tag-suggestion-item');
    items.forEach((el, i) => el.classList.toggle('active', i === idx));
    _activeIdx = idx;
};

const selectSuggestion = (tag) => {
    hideSuggestions();
    addTag(tag);
};

/**
 * Candidates for the type-ahead: every assignable term, plus — unless the taxonomy is
 * controlled — tags already in use that are not terms. Matched anywhere in the path, so
 * "bud" finds "Finance/Budget"; a match at the start of a segment ranks first.
 */
const onInput = async (e) => {
    const val = lc(e.target.value.trim());
    if (!val) { hideSuggestions(); return; }
    const [tax, used] = await Promise.all([getTaxonomy(), fetchAllTags()]);
    const pool = new Map();
    for (const n of flatten(tax.nodes)) if (!n.heading) pool.set(lc(n.path), n.path);
    if (tax.mode !== 'controlled') for (const u of used) if (!pool.has(lc(u))) pool.set(lc(u), u);
    const onPage = new Set(state.currentPageTags.map(lc));
    const rank = (p) => (lc(p).startsWith(val) || lc(p).includes('/' + val)) ? 0 : 1;
    const matches = [...pool.values()]
        .filter(p => lc(p).includes(val) && !onPage.has(lc(p)))
        .sort((a, b) => rank(a) - rank(b) || a.localeCompare(b, undefined, { sensitivity: 'base' }))
        .slice(0, 10);
    showSuggestions(matches);
};

// ── Init ──────────────────────────────────────────────────────────────────────

/**
 * Follow a tree refresh. The tree is how a tag change made in another browser arrives
 * here, and without this the open page kept showing the tags it was opened with until
 * the reader navigated away and back. The autocomplete list is dropped for the same
 * reason — it is a session cache and would never learn a tag somebody else invented.
 */
const syncFromTree = () => {
    _allTags = null;
    const entry = treeEntry(state.currentPagePath);
    // Same page by id as well as path, so a tree that has not yet caught up with a rename
    // cannot paint another page's tags here.
    if (!entry || String(entry.id) !== String(state.currentPageId)) return;
    if (JSON.stringify(entry.tags) === JSON.stringify(state.currentPageTags)) return;
    state.currentPageTags = entry.tags;
    renderTags();
};

export const init = () => {
    const input = document.getElementById('tag-input');

    document.getElementById('tag-picker-btn')?.addEventListener('click', () => {
        hideSuggestions();
        openTagPicker({ current: [...state.currentPageTags], onApply: setPageTags });
    });

    document.addEventListener('wiki:treerefresh', syncFromTree);
    document.addEventListener('wiki:frontmatter', renderFmNotice);
    document.addEventListener('wiki:fmtagssync', renderFmNotice);

    input.addEventListener('input', onInput);

    input.addEventListener('focus', () => {
        // Warm the cache silently on focus so first keystroke is instant.
        fetchAllTags();
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (_activeIdx >= 0 && _suggestions[_activeIdx]) {
                selectSuggestion(_suggestions[_activeIdx]);
            } else {
                hideSuggestions();
                addTag(e.target.value.trim());
            }
            return;
        }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive(Math.min(_activeIdx + 1, _suggestions.length - 1));
            return;
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive(Math.max(_activeIdx - 1, 0));
            return;
        }
        if (e.key === 'Escape') {
            hideSuggestions();
        }
        if (e.key === 'Tab' && _suggestions.length) {
            e.preventDefault();
            selectSuggestion(_suggestions[_activeIdx >= 0 ? _activeIdx : 0]);
        }
    });

    input.addEventListener('blur', () => {
        // Small delay so mousedown on a suggestion fires before blur hides the list.
        setTimeout(hideSuggestions, 120);
    });
};
