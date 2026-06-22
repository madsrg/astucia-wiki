import { api } from '../core/api.js';
import { showToast, promptModal } from '../core/utils.js';
import { icons } from '../core/icons.js';
import { refreshFileTree, revealAndSelectFile } from '../file_tree/index.js';
import { loadPage } from '../page_view/index.js';
import { t } from '../i18n/index.js';

const getCreationPath = () => {
    let pathPrefix = '';
    const activeEl = document.querySelector('.file-item.active > .file-item-content');
    if (activeEl) {
        const activePath = activeEl.dataset.path;
        const activeType = activeEl.dataset.type;
        if (activeType === 'folder') {
            pathPrefix = activePath + '/';
        } else {
            const parts = activePath.split('/');
            parts.pop();
            if (parts.length > 0) pathPrefix = parts.join('/') + '/';
        }
    }
    return pathPrefix;
};

const switchToTreePane = () => {
    const tab = document.querySelector('.pane-tab[data-pane="pages"]');
    if (!tab) return;
    if (!tab.classList.contains('active')) tab.click();
    if (tab.dataset.nav === 'folder') tab.click();
};

const createAndOpen = async (ext, apiAction, promptKey, defaultKey, createdKey, icon) => {
    let fileName = await promptModal(t(promptKey), t(defaultKey), '', icon);
    if (!fileName) return;
    if (!fileName.endsWith(ext)) fileName += ext;
    const path = getCreationPath() + fileName;
    const res = await api.call(apiAction, { path }, 'POST');
    if (res.success) {
        showToast(t(createdKey), 'success');
        await refreshFileTree();
        switchToTreePane();
        const newFileEl = document.querySelector(`[data-path="${path}"]`);
        if (newFileEl) { revealAndSelectFile(path); loadPage(path, newFileEl.dataset.id, []); }
    }
};

export const init = () => {
    const newItemBtn = document.getElementById('new-item-btn');
    const newItemDropdown = document.getElementById('new-item-dropdown');

    newItemBtn.addEventListener('click', (e) => { e.stopPropagation(); newItemDropdown.classList.toggle('hidden'); });
    document.addEventListener('click', () => { if (!newItemDropdown.classList.contains('hidden')) newItemDropdown.classList.add('hidden'); });

    // New Page lightbox with optional template selection
    const newPageLightbox     = document.getElementById('new-page-lightbox');
    const newPageNameInput    = document.getElementById('new-page-name');
    const newPageTemplateGroup = document.getElementById('new-page-template-group');
    const newPageTemplateList = document.getElementById('new-page-template-list');
    const newPageCreateBtn    = document.getElementById('new-page-create-btn');
    const newPageCancelBtn    = document.getElementById('new-page-cancel-btn');
    const newPageCloseBtn     = document.getElementById('new-page-close-btn');

    const closeNewPage = () => newPageLightbox.classList.add('hidden');

    let _selectedTemplate = '';

    const _selectTemplateItem = (el) => {
        newPageTemplateList.querySelectorAll('.new-page-template-item').forEach(i => i.classList.remove('active'));
        el.classList.add('active');
        _selectedTemplate = el.dataset.template;
    };

    const submitNewPage = async () => {
        let fileName = newPageNameInput.value.trim() || t('new.untitled-page');
        if (!fileName.endsWith('.md')) fileName += '.md';
        const path = getCreationPath() + fileName;
        closeNewPage();
        const params = _selectedTemplate ? { path, template: _selectedTemplate } : { path };
        const res = await api.call('create_file', params, 'POST');
        if (res.success) {
            showToast(t('new.page-created'), 'success');
            await refreshFileTree();
            switchToTreePane();
            const newFileEl = document.querySelector(`[data-path="${path}"]`);
            if (newFileEl) { revealAndSelectFile(path); loadPage(path, newFileEl.dataset.id, []); }
        }
    };

    const createPage = async () => {
        const res = await api.call('list_md_templates', {});
        const templates = res.templates || [];
        // No templates or only 'default' alone → skip lightbox
        if (templates.length === 0) {
            return createAndOpen('.md', 'create_file', 'new.page-prompt', 'new.untitled-page', 'new.page-created', icons.file);
        }
        if (templates.length === 1 && templates[0] === 'default') {
            let fileName = await promptModal(t('new.page-prompt'), t('new.untitled-page'), '', icons.file);
            if (!fileName) return;
            if (!fileName.endsWith('.md')) fileName += '.md';
            const path = getCreationPath() + fileName;
            const r = await api.call('create_file', { path, template: 'default' }, 'POST');
            if (r.success) {
                showToast(t('new.page-created'), 'success');
                await refreshFileTree();
                switchToTreePane();
                const newFileEl = document.querySelector(`[data-path="${path}"]`);
                if (newFileEl) { revealAndSelectFile(path); loadPage(path, newFileEl.dataset.id, []); }
            }
            return;
        }
        // Multiple templates — show lightbox with "Blank page" first
        const options = [{ label: t('new.page-blank-template'), value: '' }, ...templates.map(n => ({ label: n, value: n }))];
        newPageTemplateList.innerHTML = options.map(o =>
            `<div class="new-page-template-item" data-template="${o.value}">${o.label}</div>`
        ).join('');
        // Pre-select 'default' if present, otherwise 'Blank page'
        const defaultEl = newPageTemplateList.querySelector('[data-template="default"]') ||
                          newPageTemplateList.querySelector('.new-page-template-item');
        if (defaultEl) _selectTemplateItem(defaultEl);
        newPageTemplateList.querySelectorAll('.new-page-template-item').forEach(el => {
            el.addEventListener('click', () => _selectTemplateItem(el));
        });
        newPageTemplateGroup.classList.remove('hidden');
        newPageNameInput.value = '';
        newPageLightbox.classList.remove('hidden');
        newPageNameInput.focus();
    };

    newPageCreateBtn.addEventListener('click', submitNewPage);
    newPageCancelBtn.addEventListener('click', closeNewPage);
    newPageCloseBtn.addEventListener('click', closeNewPage);
    newPageLightbox.addEventListener('click', (e) => { if (e.target === newPageLightbox) closeNewPage(); });
    newPageNameInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') submitNewPage(); });

    document.getElementById('dropdown-new-page').addEventListener('click', (e) => { e.preventDefault(); newItemDropdown.classList.add('hidden'); createPage(); });
    document.getElementById('dropdown-new-diagram').addEventListener('click', (e) => { e.preventDefault(); newItemDropdown.classList.add('hidden'); createAndOpen('.drawio', 'create_diagram', 'new.diagram-prompt', 'new.untitled-diagram', 'new.diagram-created', icons.diagram); });
    document.getElementById('dropdown-new-list').addEventListener('click', (e) => { e.preventDefault(); newItemDropdown.classList.add('hidden'); createAndOpen('.list', 'create_list', 'new.list-prompt', 'new.untitled-list', 'new.list-created', icons.list); });
    const newChatLightbox   = document.getElementById('new-chat-lightbox');
    const newChatNameInput  = document.getElementById('new-chat-name');
    const newChatTopicInput = document.getElementById('new-chat-topic');
    const newChatCreateBtn  = document.getElementById('new-chat-create-btn');
    const newChatCancelBtn  = document.getElementById('new-chat-cancel-btn');
    const newChatCloseBtn   = document.getElementById('new-chat-close-btn');

    const closeNewChat = () => newChatLightbox.classList.add('hidden');

    const submitNewChat = async () => {
        let fileName = newChatNameInput.value.trim() || t('new.untitled-chat');
        if (!fileName.endsWith('.chat')) fileName += '.chat';
        const topic = newChatTopicInput.value.trim();
        const path  = getCreationPath() + fileName;
        closeNewChat();
        const res = await api.call('create_chat', { path, topic }, 'POST');
        if (res.success) {
            showToast(t('new.chat-created'), 'success');
            await refreshFileTree();
            switchToTreePane();
            const newFileEl = document.querySelector(`[data-path="${path}"]`);
            if (newFileEl) { revealAndSelectFile(path); loadPage(path, newFileEl.dataset.id, []); }
        }
    };

    document.getElementById('dropdown-new-chat').addEventListener('click', (e) => {
        e.preventDefault();
        newItemDropdown.classList.add('hidden');
        newChatNameInput.value = '';
        newChatTopicInput.value = '';
        newChatLightbox.classList.remove('hidden');
        newChatNameInput.focus();
    });
    newChatCreateBtn.addEventListener('click', submitNewChat);
    newChatCancelBtn.addEventListener('click', closeNewChat);
    newChatCloseBtn.addEventListener('click', closeNewChat);
    newChatLightbox.addEventListener('click', (e) => { if (e.target === newChatLightbox) closeNewChat(); });
    newChatNameInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') submitNewChat(); });

    document.getElementById('dropdown-new-folder').addEventListener('click', async (e) => {
        e.preventDefault();
        newItemDropdown.classList.add('hidden');
        const folderName = await promptModal(t('new.folder-prompt'), '', '', icons.folder);
        if (folderName) {
            const path = getCreationPath() + folderName;
            const res = await api.call('create_folder', { path }, 'POST');
            if (res.success) { showToast(t('new.folder-created'), 'success'); refreshFileTree(); }
        }
    });

    document.getElementById('dropdown-new-filesfolder').addEventListener('click', async (e) => {
        e.preventDefault();
        newItemDropdown.classList.add('hidden');
        const folderName = await promptModal(t('new.files-lib-prompt'), '', '', icons.filesFolder);
        if (folderName) {
            const path = getCreationPath() + folderName;
            const res = await api.call('create_filesfolder', { path }, 'POST');
            if (res.success) { showToast(t('new.files-lib-created'), 'success'); refreshFileTree(); }
            else showToast(res.message || t('new.files-lib-failed'), 'error');
        }
    });
};
