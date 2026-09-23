// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * Pasting an image into the Markdown editor.
 *
 * Hooked to the `paste` event rather than a Ctrl+V hotkey: that is the only thing that
 * sees the clipboard's contents, and it covers the context menu, middle-click and a
 * touch keyboard's paste for free. A clipboard carrying no image is left entirely to the
 * browser, so ordinary text paste is untouched.
 *
 * The upload is the same `upload_attachment` call the attach button and the image
 * lightbox make, so a pasted image is an ordinary attachment of the page: it lands in
 * `<page>.md.uploads/`, shows up in the attachments list, and is served by getfile.php.
 */
import { state } from '../core/state.js';
import { showToast } from '../core/utils.js';
import { insertMarkdown } from './editor.js';
import { renderAttachments } from '../attachments/index.js';
import { t } from '../i18n/index.js';

// A clipboard bitmap has no name of its own — the browser hands over "image.png" for
// every screenshot — so the extension comes from the MIME type. Falling back to the
// file's own extension covers an image copied out of a file manager, which does have one.
const EXT_BY_MIME = {
    'image/png':     'png',
    'image/jpeg':    'jpg',
    'image/gif':     'gif',
    'image/webp':    'webp',
    'image/svg+xml': 'svg',
    'image/bmp':     'bmp',
    'image/avif':    'avif',
};

const extFor = (file) => EXT_BY_MIME[file.type]
    ?? (file.name?.includes('.') ? file.name.split('.').pop().toLowerCase() : 'png');

// The paste lands on whichever element has focus. Ours are the classic editor's textarea
// and the one the inline editor puts inside the block being edited; a chat composer or a
// dialog's input is somebody else's paste.
const isEditorTarget = (el) => el instanceof HTMLTextAreaElement
    && (el.id === 'editor-container' || !!el.closest('.wiki-block.inline-block-editing'));

const clipboardImages = (dt) => {
    const out = [];
    for (const item of dt?.items ?? []) {
        if (item.kind !== 'file' || !item.type.startsWith('image/')) continue;
        const file = item.getAsFile();
        if (file) out.push(file);
    }
    return out;
};

// Named "image" on the way out; the server settles a collision into image2, image3 … and
// reports back which one it wrote.
const uploadPasted = async (file, pagePath, space) => {
    const form = new FormData();
    form.append('file', file, 'image.' + extFor(file));
    form.append('page_path', pagePath);
    form.append('no_overwrite', '1');
    const spaceQs = space ? `&space=${encodeURIComponent(space)}` : '';
    const resp = await fetch(`api.php?action=upload_attachment${spaceQs}`, { method: 'POST', body: form });
    return resp.json();
};

const handlePaste = async (e) => {
    if (!state.isEditing || state.currentPageType !== 'md' || !state.currentPagePath) return;
    if (!isEditorTarget(e.target)) return;

    const images = clipboardImages(e.clipboardData);
    if (images.length === 0) return;   // not an image — let the browser paste it

    e.preventDefault();
    // Captured now: an upload takes long enough for the author to navigate away, and the
    // link must not be written into whatever page they went to.
    const pagePath = state.currentPagePath;
    const space    = state.currentSpace;
    const spaceQs  = space ? `&space=${encodeURIComponent(space)}` : '';

    showToast(t('img.pasting'));
    for (const file of images) {
        let result;
        try {
            result = await uploadPasted(file, pagePath, space);
        } catch {
            result = { success: false };
        }
        if (!result.success) {
            showToast(result.message || t('img.upload-failed'), 'error');
            return;
        }
        if (state.currentPagePath !== pagePath || !state.isEditing) return;

        const name = result.filename;
        const path = `${pagePath}.uploads/${name}`;
        insertMarkdown(`![${name}](getfile.php?path=${encodeURIComponent(path)}${spaceQs})`);
    }
    showToast(t('img.uploaded'), 'success');
    renderAttachments();
};

export const init = () => {
    document.addEventListener('paste', handlePaste);
};
