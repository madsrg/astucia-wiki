// Editable viewer for .json data pages (statistics, reports, query results).
// Uses vanilla-jsoneditor (josdejong, ISC) loaded from CDN as a native ES module:
// tree mode for nested data, table mode for arrays of objects, text mode for raw.
// Falls back to a read-only pretty-printed view if the CDN is unavailable.

import { state } from '../core/state.js';
import { showToast } from '../core/utils.js';
import { t } from '../i18n/index.js';

const EDITOR_CDN = 'https://cdn.jsdelivr.net/npm/vanilla-jsoneditor/standalone.js';

let _editor = null;       // active JSONEditor instance
let _currentPath = null;
let _escHandler = null;

const canEdit = () => window.WIKI_ROLE === 'admin' || window.WIKI_ROLE === 'editor';

const isArrayOfObjects = (v) =>
    Array.isArray(v) && v.length > 0 && v.every(x => x && typeof x === 'object' && !Array.isArray(x));

// Tear down the current editor — called before re-render and when leaving a .json page.
export const destroyJsonEditor = () => {
    if (_editor) { try { _editor.destroy(); } catch (e) { /* already gone */ } _editor = null; }
    exitFullscreen();
    _currentPath = null;
};

const exitFullscreen = () => {
    const c = document.getElementById('json-view-container');
    if (c) c.classList.remove('json-fullscreen');
    if (_escHandler) { document.removeEventListener('keydown', _escHandler); _escHandler = null; }
};

const toggleFullscreen = (btn) => {
    const c = document.getElementById('json-view-container');
    if (!c) return;
    const on = c.classList.toggle('json-fullscreen');
    btn.textContent = on ? t('json.exit-fullscreen') : t('json.fullscreen');
    if (on) {
        _escHandler = (e) => { if (e.key === 'Escape') { exitFullscreen(); btn.textContent = t('json.fullscreen'); } };
        document.addEventListener('keydown', _escHandler);
    } else if (_escHandler) {
        document.removeEventListener('keydown', _escHandler); _escHandler = null;
    }
};

// Read the editor's current value as a JSON string, or null if it can't be serialised.
const currentJsonText = () => {
    if (!_editor) return null;
    const c = _editor.get();
    if (c.text !== undefined) {           // text mode — may be anything
        try { JSON.parse(c.text); } catch (e) { return null; }
        return c.text;
    }
    try { return JSON.stringify(c.json); } catch (e) { return null; }
};

const saveJson = async (saveBtn) => {
    if (!_currentPath) return;
    const text = currentJsonText();
    if (text === null) { showToast(t('json.invalid'), 'error'); return; }

    saveBtn.disabled = true;
    saveBtn.textContent = t('btn.saving');
    try {
        const spaceQs = state.currentSpace ? `&space=${encodeURIComponent(state.currentSpace)}` : '';
        const res = await fetch(`api.php?action=save&file=${encodeURIComponent(_currentPath)}${spaceQs}`, {
            method: 'POST',
            headers: { 'Content-Type': 'text/plain' },
            body: text,
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.message || 'Failed to save');
        state.hasUnsavedChanges = false;
        showToast(t('edit.saved'), 'success');
        saveBtn.textContent = t('btn.save');
        // leave disabled until the next edit
    } catch (e) {
        showToast(t('edit.save-failed', { error: e.message }), 'error');
        saveBtn.textContent = t('btn.save');
        saveBtn.disabled = false;
    }
};

// Read-only fallback used when the editor CDN can't be loaded.
const renderFallback = (container, rawText) => {
    let pretty = rawText;
    try { pretty = JSON.stringify(JSON.parse(rawText), null, 2); } catch (e) { /* keep raw */ }
    const warn = document.createElement('div');
    warn.className = 'json-error';
    warn.textContent = t('json.editor-unavailable');
    const pre = document.createElement('pre');
    pre.className = 'json-raw';
    pre.textContent = pretty;
    container.appendChild(warn);
    container.appendChild(pre);
};

// Public entry point, called by page_view when a .json page is opened.
export const renderJsonView = async (rawText, path) => {
    const container = document.getElementById('json-view-content');
    if (!container) return;
    destroyJsonEditor();
    container.innerHTML = '';
    container.scrollTop = 0;
    _currentPath = path;
    state.hasUnsavedChanges = false;

    const editable = canEdit();

    // --- our slim toolbar (Save + Full screen); mode switching is in the editor's own menu ---
    const toolbar = document.createElement('div');
    toolbar.className = 'json-toolbar';
    let saveBtn = null;
    if (editable) {                    // readers get no Save button (and a read-only editor)
        saveBtn = document.createElement('button');
        saveBtn.className = 'btn btn-blue btn-sm';
        saveBtn.textContent = t('btn.save');
        saveBtn.disabled = true;
        saveBtn.addEventListener('click', () => saveJson(saveBtn));
        toolbar.appendChild(saveBtn);
    }
    const fsBtn = document.createElement('button');
    fsBtn.className = 'btn btn-secondary btn-sm';
    fsBtn.textContent = t('json.fullscreen');
    fsBtn.addEventListener('click', () => toggleFullscreen(fsBtn));
    toolbar.appendChild(fsBtn);
    container.appendChild(toolbar);

    let parsed, parseError = false;
    try { parsed = JSON.parse(rawText); } catch (e) { parseError = true; }

    let createJSONEditor;
    try {
        ({ createJSONEditor } = await import(EDITOR_CDN));
    } catch (e) {
        renderFallback(container, rawText);
        return;
    }
    // Guard against a race where the user navigated away while the CDN was loading.
    if (_currentPath !== path) return;

    const target = document.createElement('div');
    target.className = 'json-editor-target';
    container.appendChild(target);

    _editor = createJSONEditor({
        target,
        props: {
            content: parseError ? { text: rawText } : { json: parsed },
            mode: (!parseError && isArrayOfObjects(parsed)) ? 'table' : 'tree',
            mainMenuBar: true,
            navigationBar: true,
            readOnly: !editable,
            onChange: editable ? () => {
                state.hasUnsavedChanges = true;
                if (saveBtn) saveBtn.disabled = false;
            } : undefined,
        },
    });
};
