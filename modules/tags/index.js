import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { refreshFileTree, revealAndSelectFile } from '../file_tree/index.js';

export const renderTags = () => {
    const tagsDisplay = document.getElementById('tags-display');
    tagsDisplay.innerHTML = '';
    state.currentPageTags.forEach((tag, index) => {
        const tagEl = document.createElement('span');
        tagEl.className = 'tag';
        tagEl.textContent = tag;
        const removeBtn = document.createElement('button');
        removeBtn.className = 'tag-remove';
        removeBtn.textContent = '×';
        removeBtn.onclick = () => removeTag(index);
        tagEl.appendChild(removeBtn);
        tagsDisplay.appendChild(tagEl);
    });
};

const updateTagsOnServer = async () => {
    if (!state.currentPageId) return;
    const result = await api.call('update_tags', { id: state.currentPageId, tags: JSON.stringify(state.currentPageTags) }, 'POST');
    if (result.success) {
        await refreshFileTree();
        if (state.currentPagePath) revealAndSelectFile(state.currentPagePath);
    }
};

const addTag = async (tag) => {
    if (tag && !state.currentPageTags.includes(tag)) {
        state.currentPageTags.push(tag);
        await updateTagsOnServer();
        renderTags();
    }
    document.getElementById('tag-input').value = '';
};

const removeTag = async (index) => {
    state.currentPageTags.splice(index, 1);
    await updateTagsOnServer();
    renderTags();
};

export const init = () => {
    document.getElementById('tag-input').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            addTag(e.target.value.trim());
        }
    });
};
