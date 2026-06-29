import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { refreshFileTree, revealAndSelectFile } from '../file_tree/index.js';

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
    const tagsDisplay = document.getElementById('tags-display');
    tagsDisplay.innerHTML = '';
    state.currentPageTags.forEach((tag, index) => {
        const tagEl = document.createElement('span');
        tagEl.className = 'tag';
        tagEl.textContent = tag;
        const removeBtn = document.createElement('button');
        removeBtn.className = 'tag-remove';
        removeBtn.textContent = '×';
        removeBtn.onclick = () => removeTag(index);
        tagEl.appendChild(removeBtn);
        tagsDisplay.appendChild(tagEl);
    });
};

// ── Tag CRUD ──────────────────────────────────────────────────────────────────

const updateTagsOnServer = async () => {
    if (!state.currentPageId) return;
    const result = await api.call('update_tags', { id: state.currentPageId, tags: JSON.stringify(state.currentPageTags) }, 'POST');
    if (result.success) {
        await refreshFileTree();
        if (state.currentPagePath) revealAndSelectFile(state.currentPagePath);
    }
};

const addTag = async (tag) => {
    if (tag && !state.currentPageTags.includes(tag)) {
        state.currentPageTags.push(tag);
        cacheTag(tag);
        await updateTagsOnServer();
        renderTags();
    }
    document.getElementById('tag-input').value = '';
};

const removeTag = async (index) => {
    state.currentPageTags.splice(index, 1);
    await updateTagsOnServer();
    renderTags();
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

const onInput = async (e) => {
    const val = e.target.value.trim().toLowerCase();
    if (!val) { hideSuggestions(); return; }
    const tags = await fetchAllTags();
    const matches = tags.filter(t =>
        t.toLowerCase().includes(val) &&
        !state.currentPageTags.includes(t)
    ).slice(0, 10);
    showSuggestions(matches);
};

// ── Init ──────────────────────────────────────────────────────────────────────

export const init = () => {
    const input = document.getElementById('tag-input');

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
