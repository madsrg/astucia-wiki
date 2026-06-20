import { state } from '../core/state.js';

// Splits markdown into blocks on blank lines, keeping fenced code blocks atomic.
export const splitIntoBlocks = (markdown) => {
    const lines = markdown.split('\n');
    const blocks = [];
    let current = [];
    let inFence = false;
    let fenceMarker = '';

    for (const line of lines) {
        if (!inFence) {
            const m = line.match(/^(`{3,}|~{3,})/);
            if (m) {
                inFence = true;
                fenceMarker = m[1];
                current.push(line);
            } else if (line.trim() === '') {
                if (current.length) { blocks.push(current.join('\n')); current = []; }
            } else {
                current.push(line);
            }
        } else {
            current.push(line);
            const trimmed = line.trim();
            if (trimmed.length >= fenceMarker.length && trimmed.split('').every(c => c === fenceMarker[0])) {
                inFence = false;
                fenceMarker = '';
                blocks.push(current.join('\n'));
                current = [];
            }
        }
    }
    if (current.length) blocks.push(current.join('\n'));
    return blocks.filter(b => b.trim());
};

const renderBlock = async (rawText) => {
    const { processIncludes, processDiagramTags, processListTags } = await import('../page_view/index.js');
    const processed = await processIncludes(rawText);
    const withDiagrams = await processDiagramTags(processed);
    const withLists = await processListTags(withDiagrams);
    let html = marked.parse(withLists);
    const filename = state.currentPagePath?.split('/').pop().replace(/\.(md|drawio|list)$/, '') ?? '';
    html = html.replaceAll('{filename}', filename);
    if (state.currentPageLastUpdated) {
        html = html.replaceAll('{lastUpdated}', new Date(state.currentPageLastUpdated * 1000).toLocaleString());
    }
    return html;
};

const moveToolbarInto = (blockEl) => {
    const toolbar = document.getElementById('editor-toolbar');
    if (toolbar) blockEl.appendChild(toolbar);
};

const restoreToolbar = () => {
    const toolbar = document.getElementById('editor-toolbar');
    const editorArea = document.querySelector('.editor-area');
    if (toolbar && editorArea) editorArea.parentElement.insertBefore(toolbar, editorArea);
};

const markChanged = () => {
    state.hasUnsavedChanges = true;
    const saveBtn = document.getElementById('save-btn');
    if (saveBtn) saveBtn.disabled = false;
};

let activeBlockEl = null;

const makeBlockElement = (i) => {
    const el = document.createElement('div');
    el.className = 'wiki-block';
    el.dataset.blockIndex = String(i);
    el.addEventListener('click', (e) => {
        if (e.target.closest('a, button')) return;
        activateBlock(el);
    });
    return el;
};

const commitActiveBlock = () => {
    if (!activeBlockEl) return;
    const ta = activeBlockEl.querySelector('textarea');
    if (ta) {
        const idx = parseInt(activeBlockEl.dataset.blockIndex, 10);
        state.inlineBlocks[idx] = ta.value;
        markChanged();
    }
    restoreToolbar(); // must happen before el.innerHTML replaces children
    const el = activeBlockEl;
    const idx = parseInt(el.dataset.blockIndex, 10);
    activeBlockEl = null;
    renderBlock(state.inlineBlocks[idx]).then(html => {
        el.innerHTML = html;
        el.classList.remove('inline-block-editing');
    });
};

const moveBlock = (blockEl, direction) => {
    const ta = blockEl.querySelector('textarea');
    if (ta) state.inlineBlocks[parseInt(blockEl.dataset.blockIndex, 10)] = ta.value;

    const idx = parseInt(blockEl.dataset.blockIndex, 10);
    const newIdx = idx + direction;
    if (newIdx < 0 || newIdx >= state.inlineBlocks.length) return;

    [state.inlineBlocks[idx], state.inlineBlocks[newIdx]] = [state.inlineBlocks[newIdx], state.inlineBlocks[idx]];

    const viewerContent = document.getElementById('viewer-content');
    const siblingEl = viewerContent.querySelector(`.wiki-block[data-block-index="${newIdx}"]`);
    if (!siblingEl) return;

    if (direction === -1) {
        viewerContent.insertBefore(blockEl, siblingEl);
    } else {
        siblingEl.after(blockEl);
    }

    blockEl.dataset.blockIndex = String(newIdx);
    siblingEl.dataset.blockIndex = String(idx);

    renderBlock(state.inlineBlocks[idx]).then(html => {
        siblingEl.innerHTML = html;
        siblingEl.classList.remove('inline-block-editing');
    });

    markChanged();
};

const deleteBlock = (blockEl) => {
    const idx = parseInt(blockEl.dataset.blockIndex, 10);
    state.inlineBlocks.splice(idx, 1);
    activeBlockEl = null;
    restoreToolbar(); // must happen before remove() or the toolbar is lost with the block
    blockEl.remove();

    document.getElementById('viewer-content').querySelectorAll('.wiki-block').forEach(el => {
        const i = parseInt(el.dataset.blockIndex, 10);
        if (i > idx) el.dataset.blockIndex = String(i - 1);
    });

    markChanged();
};

const addBlockAfter = (blockEl) => {
    const ta = blockEl.querySelector('textarea');
    if (ta) state.inlineBlocks[parseInt(blockEl.dataset.blockIndex, 10)] = ta.value;

    const idx = parseInt(blockEl.dataset.blockIndex, 10);
    const newIdx = idx + 1;

    state.inlineBlocks.splice(newIdx, 0, '');

    document.getElementById('viewer-content').querySelectorAll('.wiki-block').forEach(el => {
        const i = parseInt(el.dataset.blockIndex, 10);
        if (i > idx) el.dataset.blockIndex = String(i + 1);
    });

    const newEl = makeBlockElement(newIdx);
    blockEl.after(newEl);

    activateBlock(newEl); // activateBlock calls commitActiveBlock on the old block, re-rendering it
    markChanged();
};

export const activateBlock = (blockEl) => {
    if (activeBlockEl === blockEl) return;
    if (activeBlockEl) commitActiveBlock();

    const idx = parseInt(blockEl.dataset.blockIndex, 10);

    const toolbar = document.createElement('div');
    toolbar.className = 'inline-block-toolbar';

    const makeBtn = (title, text, danger, onClick) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'inline-block-action-btn' + (danger ? ' inline-block-action-btn--danger' : '');
        btn.title = title;
        btn.textContent = text;
        btn.addEventListener('mousedown', e => e.preventDefault());
        btn.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); onClick(); });
        return btn;
    };

    toolbar.appendChild(makeBtn('Move up', '↑', false, () => moveBlock(blockEl, -1)));
    toolbar.appendChild(makeBtn('Move down', '↓', false, () => moveBlock(blockEl, 1)));
    toolbar.appendChild(makeBtn('Add section below', '+', false, () => addBlockAfter(blockEl)));
    toolbar.appendChild(makeBtn('Delete section', '×', true, () => deleteBlock(blockEl)));

    const ta = document.createElement('textarea');
    ta.className = 'inline-block-textarea';
    ta.value = state.inlineBlocks[idx];
    ta.spellcheck = true;

    blockEl.innerHTML = '';
    blockEl.appendChild(toolbar);
    moveToolbarInto(blockEl);
    blockEl.appendChild(ta);
    blockEl.classList.add('inline-block-editing');
    activeBlockEl = blockEl;

    const resize = () => { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; };
    ta.addEventListener('input', () => { resize(); markChanged(); });
    requestAnimationFrame(resize);
    ta.focus();
    ta.setSelectionRange(ta.value.length, ta.value.length);
};

export const activateInlineMode = async (markdown) => {
    activeBlockEl = null;
    state.inlineBlocks = splitIntoBlocks(markdown);
    const viewerContent = document.getElementById('viewer-content');
    viewerContent.innerHTML = '';
    viewerContent.classList.add('inline-edit-active');

    for (let i = 0; i < state.inlineBlocks.length; i++) {
        const el = makeBlockElement(i);
        el.innerHTML = await renderBlock(state.inlineBlocks[i]);
        viewerContent.appendChild(el);
    }
};

export const deactivateInlineMode = () => {
    if (activeBlockEl) {
        const ta = activeBlockEl.querySelector('textarea');
        if (ta) {
            state.inlineBlocks[parseInt(activeBlockEl.dataset.blockIndex, 10)] = ta.value;
            markChanged();
        }
        activeBlockEl = null;
    }
    restoreToolbar();
    document.getElementById('viewer-content').classList.remove('inline-edit-active');
};

export const discardInlineMode = () => {
    activeBlockEl = null;
    restoreToolbar();
    document.getElementById('viewer-content').classList.remove('inline-edit-active');
};

export const getInlineContent = () => {
    if (activeBlockEl) {
        const ta = activeBlockEl.querySelector('textarea');
        if (ta) state.inlineBlocks[parseInt(activeBlockEl.dataset.blockIndex, 10)] = ta.value;
        restoreToolbar();
        activeBlockEl = null;
    }
    document.getElementById('viewer-content').classList.remove('inline-edit-active');
    return state.inlineBlocks.join('\n\n');
};
