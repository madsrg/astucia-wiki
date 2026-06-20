import { api } from '../core/api.js';
import { state } from '../core/state.js';
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

export const setEditingMode = async (editing) => {
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

    const showAttachments = !editing && state.currentPageType === 'file';
    tagsContainer.classList.toggle('hidden', editing);
    attachmentsSection.classList.toggle('hidden', !showAttachments);
    if (pageMetaRow) pageMetaRow.classList.toggle('hidden', editing || !!state.pageChatPath);
    pageActionsGroup.classList.toggle('hidden', editing);

    const pageChatBtn = document.getElementById('page-chat-btn');
    if (pageChatBtn) {
        const showChat = !editing && state.currentPageType === 'file';
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
            showToast(t('edit.click-hint'), 'info');
            await activateInlineMode(state.initialContent);
        } else {
            viewerContainer.classList.add('hidden');
            editorWrapper.classList.remove('hidden');
            searchBtn.classList.remove('hidden');
            showToast(t('edit.hotkey-hint'), 'info');

            if (indicator) indicator.style.visibility = 'visible';

            const computedStyle = window.getComputedStyle(editor);
            state.editorLineHeight = parseFloat(computedStyle.lineHeight) || 24;

            editor.addEventListener('keyup', updateLineIndicator);
            editor.addEventListener('click', updateLineIndicator);
            editor.addEventListener('scroll', updateLineIndicator);
            editor.addEventListener('input', updateLineIndicator);
            setTimeout(updateLineIndicator, 1);

            editor.focus();
        }
    } else {
        const wasInlineMode = viewerContent.classList.contains('inline-edit-active');
        if (wasInlineMode) deactivateInlineMode();

        editorWrapper.classList.add('hidden');
        viewerContainer.classList.remove('hidden');
        saveBtn.classList.add('hidden');
        cancelBtn.classList.add('hidden');
        searchBtn.classList.add('hidden');
        editBtn.classList.toggle('hidden', state.currentPageType !== 'file');
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
};

const renderCurrentPage = async (markdown) => {
    const { processIncludes, processDiagramTags, processListTags, processUserCommentTags } = await import('../page_view/index.js');
    const { extractHeadings, processTocTag } = await import('../toc/index.js');
    let content = await processIncludes(markdown);
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

export const savePage = async () => {
    if (!state.currentPagePath) return;

    const saveBtn = document.getElementById('save-btn');
    const viewerContent = document.getElementById('viewer-content');

    saveBtn.disabled = true;
    saveBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg><span>${t('edit.saving')}</span>`;

    try {
        const markdownContent = state.editMode === 'inline'
            ? getInlineContent()
            : document.getElementById('editor-container').value;
        const spaceQs = state.currentSpace ? `&space=${encodeURIComponent(state.currentSpace)}` : '';
        const saveResponse = await fetch(`api.php?action=save&file=${encodeURIComponent(state.currentPagePath)}${spaceQs}`, {
            method: 'POST',
            headers: { 'Content-Type': 'text/plain' },
            body: markdownContent,
        });

        if (!saveResponse.ok) {
            const errorData = await saveResponse.json();
            throw new Error(errorData.message || 'Failed to save');
        }

        const resultData = await saveResponse.json();
        if (resultData.success) {
            showToast(t('edit.saved'), 'success');
            state.initialContent = markdownContent;
            viewerContent.innerHTML = await renderCurrentPage(state.initialContent);
            setEditingMode(false);
        } else {
            throw new Error(resultData.message);
        }
    } catch (error) {
        showToast(t('edit.save-failed', { error: error.message }), 'error');
        saveBtn.disabled = false;
        saveBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>`;
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
            state.hasUnsavedChanges = true;
            saveBtn.disabled = false;
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
