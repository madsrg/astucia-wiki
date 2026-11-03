// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { showToast } from '../core/utils.js';

export const openDiagramEditor = () => {
    const lightbox = document.getElementById('diagram-editor-lightbox');
    const iframe = document.getElementById('diagram-editor-iframe');
    if (!state.currentPagePath) return;

    lightbox.classList.remove('hidden');
    iframe.src = `https://embed.diagrams.net/?ui=atlas&spin=1&proto=json&embed=1`;
};

export const init = () => {
    const lightbox = document.getElementById('diagram-editor-lightbox');
    const closeBtn = document.getElementById('diagram-editor-close-btn');
    const editorIframe = document.getElementById('diagram-editor-iframe');

    const closeEditor = () => {
        lightbox.classList.add('hidden');
        editorIframe.src = 'about:blank';
    };

    closeBtn?.addEventListener('click', closeEditor);

    // Tracks whether the editor should close after the SVG export response arrives.
    let pendingClose = false;

    window.addEventListener('message', async (e) => {
        if (!e.data || typeof e.data !== 'string') return;
        let msg;
        try { msg = JSON.parse(e.data); } catch { return; }

        const viewerIframe = document.querySelector('#diagram-viewer iframe');
        const isEditor = e.source === editorIframe?.contentWindow;
        const isViewer = e.source === viewerIframe?.contentWindow;

        // draw.io ready — send the diagram XML.
        if (msg.event === 'init') {
            const xml = state.initialContent || '';
            if (isEditor) {
                editorIframe.contentWindow.postMessage(JSON.stringify({ action: 'load', xml }), '*');
            } else if (isViewer) {
                viewerIframe.contentWindow.postMessage(JSON.stringify({ action: 'load', xml }), '*');
                viewerIframe.style.opacity = '1';
            }
            return;
        }

        // User saved (or Save & Exit) inside the editor.
        if (msg.event === 'save' && msg.xml && isEditor) {
            try {
                const { postPageSave } = await import('../core/save_conflict.js');
                const path = state.currentPagePath;
                let { ok, conflict, data: result } = await postPageSave(path, msg.xml);
                if (conflict) {
                    // There is no meaningful text diff of draw.io XML, so this is a plain
                    // question. Declining leaves the editor open with the drawing intact;
                    // the next save asks again, since the base is unchanged.
                    const { confirmModal } = await import('../core/utils.js');
                    const { icons } = await import('../core/icons.js');
                    const { t } = await import('../i18n/index.js');
                    const yes = await confirmModal(t('conflict.title'), {
                        message: result.modifiedBy
                            ? t('conflict.diagram-by', { name: result.modifiedBy })
                            : t('conflict.diagram'),
                        confirmLabel: t('conflict.overwrite'), cancelLabel: t('conflict.keep'),
                        dangerous: true, icon: icons.warning,
                    });
                    if (!yes || state.currentPagePath !== path) return;
                    ({ ok, data: result } = await postPageSave(path, msg.xml, { force: true }));
                }
                if (ok && result.rev) state.currentPageRev = result.rev;
                if (ok) {
                    showToast('Diagram saved!', 'success');
                    state.initialContent = msg.xml;

                    // Refresh the inline viewer with the new XML.
                    if (viewerIframe?.src !== 'about:blank') {
                        viewerIframe.contentWindow.postMessage(
                            JSON.stringify({ action: 'load', xml: msg.xml }), '*'
                        );
                    }

                    // Request SVG export — export current editor state without passing xml,
                    // so draw.io doesn't re-load content and re-dirty its modified flag.
                    // modified: false is sent after the export response arrives.
                    pendingClose = !!msg.exit;
                    editorIframe.contentWindow.postMessage(
                        JSON.stringify({ action: 'export', format: 'svg' }), '*'
                    );
                } else {
                    showToast('Diagram save failed.', 'error');
                }
            } catch {
                showToast('Diagram save failed.', 'error');
            }
            return;
        }

        // draw.io returns the exported SVG — save it to the server.
        if (msg.event === 'export' && msg.format === 'svg' && isEditor) {
            const svgData = msg.data || '';
            if (svgData && state.currentPagePath) {
                try {
                    let svgBase64;
                    if (svgData.includes(';base64,')) {
                        svgBase64 = svgData.split(';base64,')[1];
                    } else {
                        // URL-encoded SVG — convert to base64
                        const svgText = decodeURIComponent(svgData.split(',').slice(1).join(','));
                        svgBase64 = btoa(unescape(encodeURIComponent(svgText)));
                    }
                    await api.call('save_diagram_svg', { path: state.currentPagePath, svg: svgBase64 }, 'POST');
                } catch {
                    // Non-critical — inline embedding will show a fallback until next save.
                }
            }
            // Clear draw.io's modified flag now that the full save+export cycle is done.
            editorIframe.contentWindow.postMessage(
                JSON.stringify({ action: 'status', modified: false }), '*'
            );
            if (pendingClose) {
                pendingClose = false;
                closeEditor();
            }
            return;
        }

        // draw.io's own close/back button (no save).
        if (msg.event === 'exit' && isEditor) {
            // Only close if we're not already waiting for an SVG export to finish.
            if (!pendingClose) closeEditor();
        }
    });
};
