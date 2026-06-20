import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { icons } from '../core/icons.js';
import { renderTree } from '../file_tree/index.js';
import { insertMarkdown } from './editor.js';

const closeLinkLightbox = () => {
    document.getElementById('link-lightbox').classList.add('hidden');
};

const closeExternalLinkLightbox = () => {
    document.getElementById('external-link-lightbox').classList.add('hidden');
};

const loadLinkTree = async (space) => {
    const linkFileTree = document.getElementById('link-file-tree');
    linkFileTree.innerHTML = '<em style="color:var(--text-muted);padding:0.5rem">Loading…</em>';
    const result = await api.call('list', { space });
    if (result.success) {
        linkFileTree.innerHTML = '';
        renderTree(result.data, linkFileTree);
    }
};

export const openLinkLightbox = async () => {
    const spaceSelect = document.getElementById('link-space-select');
    const spaceGroup  = document.getElementById('link-space-group');

    const spacesResult = await api.call('list_spaces');
    if (spacesResult.success && Array.isArray(spacesResult.data) && spacesResult.data.length > 1) {
        spaceSelect.innerHTML = '';
        spacesResult.data.forEach(s => {
            const opt = document.createElement('option');
            opt.value = s;
            opt.textContent = s;
            if (s === state.currentSpace) opt.selected = true;
            spaceSelect.appendChild(opt);
        });
        spaceGroup.classList.remove('hidden');
    } else {
        spaceGroup.classList.add('hidden');
    }

    document.getElementById('link-lightbox').classList.remove('hidden');
    await loadLinkTree(state.currentSpace);
};

export const openExternalLinkLightbox = () => {
    const editor = document.getElementById('editor-container');
    const selectedText = editor.value.substring(editor.selectionStart, editor.selectionEnd);
    document.getElementById('external-link-text').value = selectedText;
    document.getElementById('external-link-url').value = '';
    document.getElementById('external-link-lightbox').classList.remove('hidden');
    document.getElementById('external-link-url').focus();
};

export const init = () => {
    const linkLightbox     = document.getElementById('link-lightbox');
    const linkLightboxCloseBtn = document.getElementById('link-lightbox-close-btn');
    const linkFileTree     = document.getElementById('link-file-tree');
    const spaceSelect      = document.getElementById('link-space-select');
    const editor           = document.getElementById('editor-container');

    spaceSelect.addEventListener('change', () => loadLinkTree(spaceSelect.value));

    linkFileTree.addEventListener('click', (e) => {
        const contentTarget = e.target.closest('.file-item-content');
        if (!contentTarget) return;

        const type = contentTarget.dataset.type;
        const id   = contentTarget.dataset.id;

        if (type === 'file') {
            if (state.linkInsertionMode === 'include') {
                insertMarkdown(`{include:${id}}`);
            } else {
                const linkText = editor.value.substring(editor.selectionStart, editor.selectionEnd)
                    || contentTarget.querySelector('span:last-child').textContent.trim();
                const selectedSpace = spaceSelect.value || state.currentSpace;
                const spaceSuffix = selectedSpace ? `&space=${encodeURIComponent(selectedSpace)}` : '';
                insertMarkdown(`[${linkText}](?pageid=${id}${spaceSuffix})`);
            }
            closeLinkLightbox();
        } else if (type === 'folder') {
            const childUl = contentTarget.parentElement.querySelector('ul');
            const iconEl  = contentTarget.querySelector('.folder-icon');
            if (childUl) {
                const isHidden = childUl.style.display === 'none';
                childUl.style.display = isHidden ? 'block' : 'none';
                iconEl.innerHTML = isHidden ? icons.folderOpen : icons.folder;
            }
        }
    });

    linkLightboxCloseBtn.addEventListener('click', closeLinkLightbox);
    linkLightbox.addEventListener('click', (e) => {
        if (e.target === linkLightbox) closeLinkLightbox();
    });

    // External link lightbox
    const extLightbox   = document.getElementById('external-link-lightbox');
    const extCloseBtn   = document.getElementById('external-link-close-btn');
    const extUrlInput   = document.getElementById('external-link-url');
    const extTextInput  = document.getElementById('external-link-text');
    const extInsertBtn  = document.getElementById('external-link-insert-btn');

    const doInsertExternal = () => {
        const url  = extUrlInput.value.trim();
        const text = extTextInput.value.trim() || url;
        if (!url) return;
        insertMarkdown(`[${text}](${url})`);
        closeExternalLinkLightbox();
    };

    extInsertBtn.addEventListener('click', doInsertExternal);
    extCloseBtn.addEventListener('click', closeExternalLinkLightbox);
    extLightbox.addEventListener('click', (e) => { if (e.target === extLightbox) closeExternalLinkLightbox(); });

    extUrlInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter')  { e.preventDefault(); doInsertExternal(); }
        if (e.key === 'Escape') closeExternalLinkLightbox();
    });
    extTextInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter')  { e.preventDefault(); doInsertExternal(); }
        if (e.key === 'Escape') closeExternalLinkLightbox();
    });
};
