/**
 * Knowledge graph view.
 *
 * One rendering surface — a full-screen overlay — used two ways:
 *   - whole-space map          (sidebar "Knowledge graph" button)
 *   - focus on the current page (header button, scoped to a page + N hops)
 * A toolbar toggle switches between the two without leaving the overlay.
 *
 * Nodes are pages (+ synthetic folder nodes) coloured by top-level folder and
 * sized by degree. Edges are typed: reference (solid, arrow), containment
 * (dashed) and affinity/shared-tag (dotted, faint), each toggleable. Data comes
 * from the `get_graph` API action; clicking a page node navigates to it.
 *
 * cytoscape is ~400 KB, so it is lazy-loaded from CDN the first time the graph
 * is opened rather than on every page load.
 */
import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { t } from '../i18n/index.js';
import { showToast } from '../core/utils.js';

const CYTOSCAPE_SRC = 'https://cdn.jsdelivr.net/npm/cytoscape@3.30.2/dist/cytoscape.min.js';
let _cyLoader = null;
let _onNavigate = null;
let _cy = null;              // active cytoscape instance
let _focusHops = 2;

// Cap how far the initial fit may zoom in. With only a few nodes cytoscape's
// auto-fit would otherwise blow them up to fill the viewport; this keeps nodes
// a readable size while still letting the user zoom in manually afterwards.
const MAX_INITIAL_ZOOM = 1.5;
const fitGraph = () => {
    if (!_cy) return;
    _cy.fit(undefined, 40);
    if (_cy.zoom() > MAX_INITIAL_ZOOM) {
        _cy.zoom(MAX_INITIAL_ZOOM);
        _cy.center();
    }
};

const loadCytoscape = () => {
    if (window.cytoscape) return Promise.resolve(window.cytoscape);
    if (_cyLoader) return _cyLoader;
    _cyLoader = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = CYTOSCAPE_SRC;
        s.onload = () => resolve(window.cytoscape);
        s.onerror = () => reject(new Error('Failed to load cytoscape'));
        document.head.appendChild(s);
    });
    return _cyLoader;
};

// Stable hue per top-level folder so colours are consistent across renders.
const folderColor = (name) => {
    const s = String(name || '(root)');
    let h = 0;
    for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) % 360;
    return `hsl(${h}, 62%, 55%)`;
};

const buildElements = (nodes, edges, rootId) => {
    const els = [];
    for (const n of nodes) {
        const isFolder = n.type === 'folder';
        els.push({
            data: {
                id: n.id,
                label: n.label,
                path: n.path,
                type: n.type,
                color: isFolder ? '#94a3b8' : folderColor(n.folder),
                size: Math.max(18, Math.min(56, 16 + (n.degree || 0) * 5)),
                isRoot: n.id === rootId,
            },
            classes: (isFolder ? 'folder' : 'page') + (n.id === rootId ? ' root' : ''),
        });
    }
    for (const e of edges) {
        els.push({
            data: { id: `${e.type}:${e.source}->${e.target}`, source: e.source, target: e.target, etype: e.type },
            classes: e.type + (e.directed ? ' directed' : ''),
        });
    }
    return els;
};

const stylesheet = () => ([
    {
        selector: 'node',
        style: {
            'background-color': 'data(color)',
            'label': 'data(label)',
            'width': 'data(size)',
            'height': 'data(size)',
            'font-size': '10px',
            'color': '#e5e7eb',
            'text-outline-color': '#111827',
            'text-outline-width': 2,
            'text-valign': 'bottom',
            'text-margin-y': 3,
            'min-zoomed-font-size': 6,
        },
    },
    { selector: 'node.folder', style: { 'shape': 'round-rectangle', 'background-opacity': 0.65, 'font-style': 'italic' } },
    { selector: 'node.root',   style: { 'border-width': 3, 'border-color': '#f59e0b' } },
    { selector: 'edge', style: { 'width': 1.4, 'curve-style': 'bezier', 'opacity': 0.7 } },
    { selector: 'edge.directed', style: { 'target-arrow-shape': 'triangle', 'arrow-scale': 0.8 } },
    { selector: 'edge.reference',   style: { 'line-color': '#60a5fa', 'target-arrow-color': '#60a5fa' } },
    { selector: 'edge.containment', style: { 'line-color': '#a3a3a3', 'line-style': 'dashed' } },
    { selector: 'edge.tag',         style: { 'line-color': '#34d399', 'line-style': 'dotted', 'opacity': 0.45 } },
    { selector: '.dimmed', style: { 'opacity': 0.12 } },
]);

const ensureOverlay = () => {
    let ov = document.getElementById('graph-overlay');
    if (ov) return ov;
    ov = document.createElement('div');
    ov.id = 'graph-overlay';
    ov.className = 'graph-overlay hidden';
    ov.innerHTML = `
        <div class="graph-toolbar">
            <span class="graph-title" id="graph-title"></span>
            <span class="graph-spacer"></span>
            <button class="graph-scope-btn btn btn-sm btn-secondary" id="graph-scope-btn"></button>
            <label class="graph-toggle"><input type="checkbox" data-etype="reference" checked> <span style="color:#60a5fa">${t('graph.edge-reference')}</span></label>
            <label class="graph-toggle"><input type="checkbox" data-etype="containment" checked> <span style="color:#a3a3a3">${t('graph.edge-containment')}</span></label>
            <label class="graph-toggle"><input type="checkbox" data-etype="tag" checked> <span style="color:#34d399">${t('graph.edge-tag')}</span></label>
            <button class="graph-fit-btn btn btn-sm btn-secondary" id="graph-fit-btn">${t('graph.fit')}</button>
            <button class="graph-close-btn" id="graph-close-btn" title="${t('graph.close')}">&times;</button>
        </div>
        <div class="graph-canvas" id="graph-canvas"></div>
        <div class="graph-empty hidden" id="graph-empty"></div>`;
    document.body.appendChild(ov);

    ov.querySelector('#graph-close-btn').addEventListener('click', closeOverlay);
    ov.querySelector('#graph-fit-btn').addEventListener('click', fitGraph);
    ov.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeOverlay(); });
    ov.querySelectorAll('.graph-toggle input').forEach(cb => {
        cb.addEventListener('change', () => {
            if (!_cy) return;
            _cy.edges(`.${cb.dataset.etype}`).style('display', cb.checked ? 'element' : 'none');
        });
    });
    ov.querySelector('#graph-scope-btn').addEventListener('click', () => {
        // Toggle between whole-space and focus-on-current-page.
        const root = ov.dataset.root ? '' : (state.currentPageId ? String(state.currentPageId) : '');
        openGraphOverlay(root || null);
    });
    return ov;
};

const closeOverlay = () => {
    const ov = document.getElementById('graph-overlay');
    if (ov) ov.classList.add('hidden');
    if (_cy) { _cy.destroy(); _cy = null; }
};

/**
 * Open the graph overlay. Pass a pageId to focus on that page's neighbourhood,
 * or null/undefined for the whole-space map.
 */
export const openGraphOverlay = async (rootId = null) => {
    const ov = ensureOverlay();
    ov.classList.remove('hidden');
    ov.dataset.root = rootId || '';
    const canvas = ov.querySelector('#graph-canvas');
    const empty  = ov.querySelector('#graph-empty');
    empty.classList.add('hidden');
    ov.querySelector('#graph-title').textContent = rootId ? t('graph.title-focus') : t('graph.title-space');
    ov.querySelector('#graph-scope-btn').textContent = rootId ? t('graph.show-space') : t('graph.focus-page');
    ov.querySelector('#graph-scope-btn').classList.toggle('hidden', !rootId && !state.currentPageId);

    let cytoscape;
    try {
        cytoscape = await loadCytoscape();
    } catch {
        showToast(t('graph.load-failed'), 'error');
        return;
    }

    const params = rootId ? { root: rootId, hops: _focusHops } : {};
    const res = await api.call('get_graph', params);
    if (!res || !res.success) return;
    if (!res.nodes.length) {
        if (_cy) { _cy.destroy(); _cy = null; }
        empty.textContent = t('graph.empty');
        empty.classList.remove('hidden');
        return;
    }

    if (_cy) { _cy.destroy(); _cy = null; }
    _cy = cytoscape({
        container: canvas,
        elements: buildElements(res.nodes, res.edges, rootId),
        style: stylesheet(),
        wheelSensitivity: 0.2,
        minZoom: 0.1,
        maxZoom: 2.5,
    });

    // Run the layout explicitly so we can clamp the zoom once it settles —
    // registering the handler before run() avoids missing a synchronous stop.
    const layout = _cy.layout({
        name: 'cose',
        animate: false,
        nodeRepulsion: 8000,
        idealEdgeLength: 90,
        padding: 40,
        fit: true,
    });
    layout.one('layoutstop', fitGraph);
    layout.run();

    // Reapply current edge-type toggles to the fresh instance.
    ov.querySelectorAll('.graph-toggle input').forEach(cb => {
        if (!cb.checked) _cy.edges(`.${cb.dataset.etype}`).style('display', 'none');
    });

    // Hover highlight: dim everything not adjacent to the hovered node.
    _cy.on('mouseover', 'node', (e) => {
        const nhood = e.target.closedNeighborhood();
        _cy.elements().addClass('dimmed');
        nhood.removeClass('dimmed');
    });
    _cy.on('mouseout', 'node', () => _cy.elements().removeClass('dimmed'));

    // Click a page node → navigate; folder nodes just re-centre.
    _cy.on('tap', 'node', (e) => {
        const d = e.target.data();
        if (d.type === 'page' && _onNavigate) {
            closeOverlay();
            _onNavigate(d.id);
        } else {
            _cy.animate({ center: { eles: e.target }, zoom: 1.2 }, { duration: 250 });
        }
    });
};

export const init = ({ onNavigate } = {}) => {
    _onNavigate = onNavigate;

    document.getElementById('graph-btn')?.addEventListener('click', () => openGraphOverlay(null));
    document.getElementById('graph-focus-btn')?.addEventListener('click', () => {
        if (state.currentPageId) openGraphOverlay(String(state.currentPageId));
    });
};
