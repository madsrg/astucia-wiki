import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { loadPage } from '../page_view/index.js';
import { revealAndSelectFile } from '../file_tree/index.js';
import { t } from '../i18n/index.js';

export const generateTagCloud = (fileTree) => {
    const allTags = {};
    const collectTags = (items) => {
        items.forEach(item => {
            if (item.tags) item.tags.forEach(tag => { allTags[tag] = (allTags[tag] || 0) + 1; });
            if (item.children) collectTags(item.children);
        });
    };
    collectTags(fileTree);

    const tagCloud = document.getElementById('tag-cloud');
    tagCloud.innerHTML = '';
    Object.keys(allTags).sort().forEach(tag => {
        const tagEl = document.createElement('span');
        tagEl.className = 'tag-cloud-item';
        tagEl.textContent = `${tag} (${allTags[tag]})`;
        tagEl.dataset.tag = tag;
        tagCloud.appendChild(tagEl);
    });
};

const PAGE_TYPE = (path) => {
    if (path.endsWith('.drawio')) return { label: 'diagram', cls: 'sr-type-diagram' };
    if (path.endsWith('.list'))   return { label: 'list',    cls: 'sr-type-list'    };
    if (path.endsWith('.chat'))   return { label: 'chat',    cls: 'sr-type-chat'    };
    return { label: 'page', cls: 'sr-type-page' };
};

const fmtDate = (ts) => {
    if (!ts) return null;
    const diff = Math.floor(Date.now() / 1000) - ts;
    if (diff < 60)          return 'just now';
    if (diff < 3600)        return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400)       return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 7 * 86400)   return `${Math.floor(diff / 86400)}d ago`;
    return new Date(ts * 1000).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
};

const escHtml = (s) => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

const buildResultCard = (page) => {
    const segments   = page.path.replace(/\.(md|drawio|list|chat)$/, '').split('/');
    const name       = segments.pop();
    const folderPath = segments.join(' / ');
    const type       = PAGE_TYPE(page.path);
    const heading    = (page.header || '').replace(/^#+\s*/, '').trim();

    const tagsHtml = (page.tags || []).length
        ? `<span class="sr-tags">${page.tags.map(t => `<span class="sr-tag">${escHtml(t)}</span>`).join('')}</span>`
        : '';

    const metaParts = [];
    if (page.created) {
        const who = page.createdBy?.name ? ` by ${escHtml(page.createdBy.name)}` : '';
        metaParts.push(`<span class="sr-meta-item">Created ${fmtDate(page.created)}${who}</span>`);
    }
    if (page.updated && page.updated !== page.created) {
        const who = page.updatedBy?.name ? ` by ${escHtml(page.updatedBy.name)}` : '';
        metaParts.push(`<span class="sr-meta-item">Updated ${fmtDate(page.updated)}${who}</span>`);
    }
    const metaHtml = metaParts.length ? `<div class="sr-meta">${metaParts.join('<span class="sr-meta-sep">·</span>')}${tagsHtml}</div>` : (tagsHtml ? `<div class="sr-meta">${tagsHtml}</div>` : '');

    return `
        <div class="sr-card">
            <div class="sr-card-top">
                <a href="#" class="sr-title search-result-link" data-id="${page.id}">${escHtml(name)}</a>
                <span class="sr-type-badge ${type.cls}">${type.label}</span>
            </div>
            ${folderPath ? `<div class="sr-path">${escHtml(folderPath)}</div>` : ''}
            ${heading    ? `<div class="sr-heading">${escHtml(heading)}</div>` : ''}
            ${page.preview ? `<div class="sr-preview">${page.preview}</div>` : ''}
            ${metaHtml}
        </div>`;
};

export const displaySearchResults = (title, results) => {
    state.currentPagePath = null;
    state.currentPageId = null;
    const count = results.length;
    document.getElementById('current-page-title').textContent = `${title} (${count})`;
    document.getElementById('page-id-display').classList.add('hidden');
    document.getElementById('edit-btn').disabled = true;
    document.getElementById('viewer-container').classList.remove('hidden');
    document.getElementById('list-view-container').classList.add('hidden');
    document.getElementById('chat-view-container').classList.add('hidden');
    document.getElementById('diagram-viewer').classList.add('hidden');
    document.getElementById('viewer-content').classList.remove('hidden');

    let html = `<div class="search-results">`;
    if (count === 0) {
        html += `<div class="sr-empty">${t('search.no-results')}</div>`;
    } else {
        results.forEach(page => { html += buildResultCard(page); });
    }
    html += `</div>`;
    document.getElementById('viewer-content').innerHTML = html;

    // Clear edit state
    document.getElementById('tags-container').classList.add('hidden');
    document.getElementById('attachments-section').classList.add('hidden');
    document.getElementById('page-actions-group').classList.add('hidden');
    document.getElementById('save-btn').classList.add('hidden');
    document.getElementById('cancel-btn').classList.add('hidden');
    document.querySelector('.editor-container-wrapper').classList.add('hidden');
    document.getElementById('viewer-container').classList.remove('hidden');
    document.getElementById('edit-btn').classList.add('hidden');
    document.getElementById('editor-mode-group')?.classList.add('hidden');
};

const performSearch = async () => {
    const query = document.getElementById('search-query-input').value.trim();
    if (!query) return;
    const result = await api.call('search', { query });
    if (result.success) displaySearchResults(t('search.results', { query }), result.data);
};

export const init = () => {
    const tagCloud = document.getElementById('tag-cloud');
    const searchQueryInput = document.getElementById('search-query-input');
    const searchQueryBtn = document.getElementById('search-query-btn');
    const viewerContent = document.getElementById('viewer-content');

    tagCloud.addEventListener('click', async (e) => {
        if (e.target.classList.contains('tag-cloud-item')) {
            const tag = e.target.dataset.tag;
            const result = await api.call('get_pages_by_tag', { tag });
            if (result.success) displaySearchResults(t('search.tag-results', { tag }), result.data);
        }
    });

    searchQueryBtn.addEventListener('click', performSearch);
    searchQueryInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') performSearch(); });

    // Click on a search result link
    viewerContent.addEventListener('click', async (e) => {
        const link = e.target.closest('.search-result-link');
        if (!link) return;
        e.preventDefault();
        const pageId = link.dataset.id;
        const result = await api.call('get_path_from_id', { pageid: pageId });
        if (result.success && result.path) {
            const findItem = (items, path) => {
                for (const item of items) {
                    if (item.path === path) return item;
                    if (item.children) { const found = findItem(item.children, path); if (found) return found; }
                }
                return null;
            };
            const item = findItem(state.fullFileTree, result.path);
            document.querySelector('.pane-tab[data-pane="pages"]').click();
            await loadPage(result.path, pageId, item?.tags || []);
            revealAndSelectFile(result.path);
            history.pushState({ pageId }, '', `?pageid=${pageId}`);
        }
    });
};
