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
                const spaceQs = state.currentSpace ? `&space=${encodeURIComponent(state.currentSpace)}` : '';
                const response = await fetch(
                    `api.php?action=save&file=${encodeURIComponent(state.currentPagePath)}${spaceQs}`,
                    { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: msg.xml }
                );
                const result = await response.json();
                if (result.success) {
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
