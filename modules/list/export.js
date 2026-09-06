// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
import { state } from '../core/state.js';

// export.php resolves the path against PAGES_DIR/<space>, so the current Space has to
// travel with the request — without it a list inside a Space is simply not found.
const spaceQs = () => state.currentSpace ? `&space=${encodeURIComponent(state.currentSpace)}` : '';

export const init = () => {
    const exportBtn = document.getElementById('export-btn');
    const exportDropdown = document.getElementById('export-dropdown');

    exportBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        exportDropdown.classList.toggle('hidden');
    });

    document.addEventListener('click', (e) => {
        if (!exportBtn.contains(e.target) && !exportDropdown.classList.contains('hidden')) {
            exportDropdown.classList.add('hidden');
        }
    });

    document.getElementById('export-json').addEventListener('click', (e) => {
        e.preventDefault();
        const itemsOnly = state.currentListData?.items || [];
        const dataStr = 'data:text/json;charset=utf-8,' + encodeURIComponent(JSON.stringify(itemsOnly, null, 2));
        const a = document.createElement('a');
        a.setAttribute('href', dataStr);
        a.setAttribute('download', state.currentPagePath.split('/').pop().replace('.list', '') + '.json');
        document.body.appendChild(a);
        a.click();
        a.remove();
        exportDropdown.classList.add('hidden');
    });

    document.getElementById('export-xml').addEventListener('click', (e) => {
        e.preventDefault();
        window.open(`export.php?path=${encodeURIComponent(state.currentPagePath)}&format=xml${spaceQs()}`);
        exportDropdown.classList.add('hidden');
    });

    document.getElementById('export-csv').addEventListener('click', (e) => {
        e.preventDefault();
        window.open(`export.php?path=${encodeURIComponent(state.currentPagePath)}&format=csv${spaceQs()}`);
        exportDropdown.classList.add('hidden');
    });
};
