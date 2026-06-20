import { state } from '../core/state.js';

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
        window.open(`export.php?path=${encodeURIComponent(state.currentPagePath)}&format=xml`);
        exportDropdown.classList.add('hidden');
    });

    document.getElementById('export-csv').addEventListener('click', (e) => {
        e.preventDefault();
        window.open(`export.php?path=${encodeURIComponent(state.currentPagePath)}&format=csv`);
        exportDropdown.classList.add('hidden');
    });
};
