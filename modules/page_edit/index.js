// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { api } from '../core/api.js';
import { state, notifyPageState } from '../core/state.js';
import { processWikiLinks } from '../wikilinks/index.js';
import { showToast, confirmModal } from '../core/utils.js';
import { icons } from '../core/icons.js';
import { activateInlineMode, deactivateInlineMode, discardInlineMode, getInlineContent } from './inline_editor.js';
import { t } from '../i18n/index.js';

export const updateLineIndicator = () => {
    const editor = document.getElementById('editor-container');
    const indicator = document.getElementById('line-indicator');
    if (!state.isEditing || !indicator) return;

    const textToCursor = editor.value.substring(0, editor.selectionStart);
    const lineNumber = textToCursor.split('\n').length - 1;
    const paddingTop = parseInt(window.getComputedStyle(editor).paddingTop, 10);
    const newTop = (lineNumber * state.editorLineHeight) - editor.scrollTop + paddingTop - 3;
    indicator.style.top = `${newTop}px`;
};

/**
 * @param {boolean} editing
 * @param {{silent?: boolean}} [opts] `silent` skips the "how to use the editor" hint. The
 *        tab manager re-enters edit mode every time you switch back to a tab with unsaved
 *        work, and a hint on each switch would be noise rather than help.
 */
export const setEditingMode = async (editing, opts = {}) => {
    // A frozen Space has no edit mode. CSS hides the controls, but the Ctrl+E hotkey
    // clicks #edit-btn directly and a display:none button still fires its handler —
    // so the one function every route into edit mode goes through says no.
    if (editing && state.spaceReadOnly) {
        showToast(t('spaces.readonly-toast', { name: state.currentSpace || '' }), 'error');
        return;
    }
    state.isEditing = editing;
    const editor = document.getElementById('editor-container');
    const editorWrapper = document.querySelector('.editor-container-wrapper');
    const viewerContainer = document.getElementById('viewer-container');
    const viewerContent = document.getElementById('viewer-content');
    const saveBtn = document.getElementById('save-btn');
    const cancelBtn = document.getElementById('cancel-btn');
    const editBtn = document.getElementById('edit-btn');
    const searchBtn = document.getElementById('search-btn');
    const searchReplaceBar = document.getElementById('search-replace-bar');
    const tagsContainer = document.getElementById('tags-container');
    const attachmentsSection = document.getElementById('attachments-section');
    const pageMetaRow = document.getElementById('page-meta-row');
    const pageActionsGroup = document.getElementById('page-actions-group');
    const indicator = document.getElementById('line-indicator');

    const showAttachments = !editing && state.currentPageType === 'md';
    tagsContainer.classList.toggle('hidden', editing);
    attachmentsSection.classList.toggle('hidden', !showAttachments);
    if (pageMetaRow) pageMetaRow.classList.toggle('hidden', editing || !!state.pageChatPath);
    pageActionsGroup.classList.toggle('hidden', editing);

    const pageChatBtn = document.getElementById('page-chat-btn');
    if (pageChatBtn) {
        const showChat = !editing && state.currentPageType === 'md';
        pageChatBtn.classList.toggle('hidden', !showChat);
    }
    if (editing && state.pageChatPath) {
        import('../page_chat/index.js').then(m => m.closePanel());
    }

    if (editing) {
        editBtn.classList.add('hidden');
        saveBtn.classList.remove('hidden');
        cancelBtn.classList.remove('hidden');
        document.getElementById('toc-panel')?.classList.remove('open');
        document.getElementById('toc-btn')?.classList.add('hidden');

        if (state.editMode === 'inline') {
            editorWrapper.classList.add('hidden');
            viewerContainer.classList.remove('hidden');
            searchBtn.classList.add('hidden');
            if (indicator) indicator.style.visibility = 'hidden';
            if (!opts.silent) showToast(t('edit.click-hint'), 'info');
            await activateInlineMode(state.initialContent);
        } else {
            viewerContainer.classList.add('hidden');
            editorWrapper.classList.remove('hidden');
            searchBtn.classList.remove('hidden');
            if (!opts.silent) showToast(t('edit.hotkey-hint'), 'info');

            if (indicator) indicator.style.visibility = 'visible';

            const computedStyle = window.getComputedStyle(editor);
            state.editorLineHeight = parseFloat(computedStyle.lineHeight) || 24;

            editor.addEventListener('keyup', updateLineIndicator);
            editor.addEventListener('click', updateLineIndicator);
            editor.addEventListener('scroll', updateLineIndicator);
            editor.addEventListener('input', updateLineIndicator);
            setTimeout(updateLineIndicator, 1);

            editor.focus();
            // Start at the first character, not wherever the caret last was. focus() on a
            // textarea leaves the caret at the end of the value, so opening a long page
            // for editing dropped you at the bottom of it; scrollTop is reset too, since
            // focusing scrolls the caret into view.
            editor.setSelectionRange(0, 0);
            editor.scrollTop = 0;
        }
    } else {
        const wasInlineMode = viewerContent.classList.contains('inline-edit-active');
        if (wasInlineMode) deactivateInlineMode();

        editorWrapper.classList.add('hidden');
        viewerContainer.classList.remove('hidden');
        saveBtn.classList.add('hidden');
        cancelBtn.classList.add('hidden');
        searchBtn.classList.add('hidden');
        editBtn.classList.toggle('hidden', state.currentPageType !== 'md');
        const _nav = document.getElementById('toc-panel-nav');
        const _tb  = document.getElementById('toc-btn');
        if (_tb && _nav?.children.length > 0) _tb.classList.remove('hidden');
        state.hasUnsavedChanges = false;
        saveBtn.disabled = true;
        saveBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>`;
        searchReplaceBar.classList.add('hidden');

        if (indicator) indicator.style.visibility = 'hidden';

        if (!wasInlineMode) {
            editor.removeEventListener('keyup', updateLineIndicator);
            editor.removeEventListener('click', updateLineIndicator);
            editor.removeEventListener('scroll', updateLineIndicator);
            editor.removeEventListener('input', updateLineIndicator);
        }
    }
    notifyPageState();
};

const renderCurrentPage = async (markdown) => {
    const { processIncludes, processDiagramTags, processListTags, processUserCommentTags } = await import('../page_view/index.js');
    const { extractHeadings, processTocTag } = await import('../toc/index.js');
    let content = await processIncludes(processWikiLinks(markdown));
    content = await processDiagramTags(content);
    content = await processListTags(content);
    content = await processUserCommentTags(content);
    const headings = extractHeadings(content);
    content = processTocTag(content, headings);
    let html = marked.parse(content);
    const filename = state.currentPagePath.split('/').pop().replace(/\.(md|drawio|list)$/, '');
    html = html.replaceAll('{filename}', filename);
    if (state.currentPageLastUpdated) {
        html = html.replaceAll('{lastUpdated}', new Date(state.currentPageLastUpdated * 1000).toLocaleString());
    }
    return html;
};

const SAVE_ICON = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>`;

const resetSaveButton = () => {
    const saveBtn = document.getElementById('save-btn');
    saveBtn.disabled = false;
    saveBtn.innerHTML = SAVE_ICON;
};

/**
 * The page moved on while it was being edited, and the save was refused (see
 * modules/core/save_conflict.js). Nothing has been written; ask what to do.
 */
const resolveSaveConflict = async (path, mine, current) => {
    // Somebody saved exactly this text already: nothing to choose between.
    if (current.data === mine) return savePage({ force: true });

    const { askSaveConflict, stashOnClipboard } = await import('../core/save_conflict.js');
    const choice = await askSaveConflict({
        mine, theirs: current.data, modifiedBy: current.modifiedBy, lastUpdated: current.lastUpdated,
    });
    if (state.currentPagePath !== path) return;            // navigated away meanwhile

    if (choice === 'overwrite') return savePage({ force: true });

    if (choice === 'theirs') {
        // The edit is about to be dropped, so it goes to the clipboard first — and if the
        // browser will not allow that, it stays in the editor instead of being lost.
        if (!await stashOnClipboard(mine)) {
            showToast(t('conflict.copy-failed'), 'error');
            resetSaveButton();
            return;
        }
        state.hasUnsavedChanges = false;
        await setEditingMode(false);
        const { refreshPageContent, rebaselineFileWatch } = await import('../page_view/index.js');
        await refreshPageContent();
        rebaselineFileWatch(path, state.currentPageLastUpdated, state.currentPageSize);
        showToast(t('conflict.copied'), 'info');
        return;
    }

    // Keep editing. The author has now seen the other version, so their next save is
    // based on it and goes through — which is what "I'll merge it myself" means. The
    // watcher's baseline is left alone, so cancelling still reloads the page from disk.
    state.currentPageRev = current.rev;
    showToast(t('conflict.kept'), 'info');
    resetSaveButton();
};

export const savePage = async (opts) => {
    if (!state.currentPagePath) return;
    // Also the save button's click handler, so `opts` may be an Event.
    const force = opts?.force === true;

    const saveBtn = document.getElementById('save-btn');
    const viewerContent = document.getElementById('viewer-content');

    saveBtn.disabled = true;
    saveBtn.innerHTML = `${SAVE_ICON}<span>${t('edit.saving')}</span>`;

    try {
        const path = state.currentPagePath;
        const markdownContent = state.editMode === 'inline'
            ? getInlineContent()
            : document.getElementById('editor-container').value;
        const { postPageSave } = await import('../core/save_conflict.js');
        const { ok, conflict, data: resultData } = await postPageSave(path, markdownContent, { force });

        if (conflict) return resolveSaveConflict(path, markdownContent, resultData);
        if (!ok) throw new Error(resultData.message || t('edit.save-error'));

        showToast(t('edit.saved'), 'success');
        // Tell the on-disk watcher these bytes are ours, or its next poll reports
        // this very save as an external change and reloads the page. The rev rides
        // along: it is the base of the next save.
        const { rebaselineFileWatch, updateFrontmatterBadge } = await import('../page_view/index.js');
        rebaselineFileWatch(path, resultData.lastUpdated, resultData.size, resultData.rev);
        // The save may have changed the page's own front matter — automatic stamping
        // rewrites four fields, and can add the block to a page that had none. The
        // cached copy came from the `get` that rendered the page, so without taking the
        // block back off the response the Metadata panel and its indicator keep
        // describing the state before this save.
        if (resultData.frontmatter !== undefined) {
            state.currentPageFrontmatter = resultData.frontmatter ?? null;
            state.currentPageFrontmatterNested = resultData.frontmatter_nested ?? null;
            state.currentPageFrontmatterManaged = resultData.frontmatter_managed ?? null;
            updateFrontmatterBadge();
        }
        state.initialContent = markdownContent;
        viewerContent.innerHTML = await renderCurrentPage(state.initialContent);
        setEditingMode(false);
    } catch (error) {
        showToast(t('edit.save-failed', { error: error.message }), 'error');
        resetSaveButton();
    }
};

export const init = () => {
    const editor = document.getElementById('editor-container');
    const saveBtn = document.getElementById('save-btn');
    const cancelBtn = document.getElementById('cancel-btn');
    const editBtn = document.getElementById('edit-btn');

    // --- Editor mode toggle (in header, always accessible for .md pages) ---
    const modeToggleBtn = document.createElement('button');
    modeToggleBtn.id = 'editor-mode-group';
    modeToggleBtn.className = 'btn btn-secondary btn-mode-toggle hidden';

    const updateModeButtons = (mode) => {
        const isInline = mode === 'inline';
        modeToggleBtn.textContent = isInline ? t('edit.inline-btn') : t('edit.classic-btn');
        modeToggleBtn.title = isInline ? t('edit.classic-title') : t('edit.inline-title');
    };
    updateModeButtons(state.editMode);

    const switchToMode = async (mode) => {
        if (state.editMode === mode) return;

        if (state.isEditing) {
            if (state.hasUnsavedChanges && !await confirmModal(t('edit.discard-confirm'), { message: t('edit.discard-switch'), confirmLabel: t('btn.discard'), dangerous: true, icon: icons.warning })) return;
            state.hasUnsavedChanges = false;

            // Clean up current mode before switching
            if (state.editMode === 'inline') {
                discardInlineMode();
                document.getElementById('viewer-content').innerHTML = await renderCurrentPage(state.initialContent);
            } else {
                editor.value = state.initialContent;
            }

            state.editMode = mode;
            localStorage.setItem('wiki_editMode', mode);
            updateModeButtons(mode);
            state.isEditing = false; // let setEditingMode enter the "entering" branch cleanly
            await setEditingMode(true);
        } else {
            state.editMode = mode;
            localStorage.setItem('wiki_editMode', mode);
            updateModeButtons(mode);
        }
    };

    modeToggleBtn.addEventListener('click', () => switchToMode(state.editMode === 'classic' ? 'inline' : 'classic'));

    document.querySelector('.header-actions').insertBefore(modeToggleBtn, editBtn);

    editor.addEventListener('input', () => {
        if (state.isEditing && editor.value !== state.initialContent) {
            const wasClean = !state.hasUnsavedChanges;
            state.hasUnsavedChanges = true;
            saveBtn.disabled = false;
            if (wasClean) notifyPageState();   // only the clean→dirty edge is news
        }
    });

    saveBtn.addEventListener('click', savePage);

    cancelBtn.addEventListener('click', async () => {
        if (state.hasUnsavedChanges && !await confirmModal(t('edit.discard-confirm'), { confirmLabel: t('btn.discard'), dangerous: true, icon: icons.warning })) return;

        if (state.editMode === 'inline') {
            discardInlineMode();
            document.getElementById('viewer-content').innerHTML = await renderCurrentPage(state.initialContent);
        } else {
            editor.value = state.initialContent;
        }
        setEditingMode(false);
    });

    editBtn.addEventListener('click', () => setEditingMode(true));

    document.getElementById('diagram-edit-btn').addEventListener('click', async () => {
        const { openDiagramEditor } = await import('../diagram/index.js');
        openDiagramEditor();
    });
};
