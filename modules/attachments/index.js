import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { showToast, confirmModal } from '../core/utils.js';
import { icons } from '../core/icons.js';
import { t } from '../i18n/index.js';

export const renderAttachments = async () => {
    const attachmentsSection = document.getElementById('attachments-section');
    const attachmentList = document.getElementById('attachment-list');

    if (!state.currentPagePath || state.currentPageType !== 'file') {
        attachmentsSection.classList.add('hidden');
        return;
    }
    const result = await api.call('list_attachments', { page_path: state.currentPagePath });
    if (result.success) {
        attachmentList.innerHTML = '';
        if (result.data.length > 0) {
            result.data.forEach(filename => {
                const el = document.createElement('div');
                el.className = 'attachment-item';
                const filePath = state.currentPagePath + '.uploads/' + filename;
                const spaceParam = state.currentSpace ? `&space=${encodeURIComponent(state.currentSpace)}` : '';
                el.innerHTML = `
                    <a href="getfile.php?path=${encodeURIComponent(filePath)}${spaceParam}" target="_blank">${filename}</a>
                    <button class="remove-attachment-btn" data-filename="${filename}">&times;</button>
                `;
                attachmentList.appendChild(el);
            });
        }
        attachmentsSection.classList.remove('hidden');
        document.getElementById('attach-file-btn').classList.toggle('hidden', state.currentPageType !== 'file');
    }
};

export const init = () => {
    const attachmentList = document.getElementById('attachment-list');
    const attachFileBtn = document.getElementById('attach-file-btn');
    const fileUploadInput = document.getElementById('file-upload-input');

    attachmentList.addEventListener('click', async (e) => {
        if (e.target.classList.contains('remove-attachment-btn')) {
            const filename = e.target.dataset.filename;
            if (await confirmModal(t('attach.delete-confirm', { name: filename }), { confirmLabel: t('btn.delete'), dangerous: true, icon: icons.trash })) {
                const result = await api.call('delete_attachment', { page_path: state.currentPagePath, filename }, 'POST');
                if (result.success) {
                    showToast(t('attach.deleted'), 'success');
                    renderAttachments();
                }
            }
        }
    });

    attachFileBtn.addEventListener('click', () => fileUploadInput.click());

    fileUploadInput.addEventListener('change', async () => {
        if (fileUploadInput.files.length > 0) {
            const file = fileUploadInput.files[0];
            const formData = new FormData();
            formData.append('file', file);
            formData.append('page_path', state.currentPagePath);

            const spaceQs = state.currentSpace ? `&space=${encodeURIComponent(state.currentSpace)}` : '';
            const response = await fetch(`api.php?action=upload_attachment${spaceQs}`, { method: 'POST', body: formData });
            const result = await response.json();
            if (result.success) {
                showToast(t('attach.uploaded'), 'success');
                renderAttachments();
            } else {
                showToast(result.message || t('attach.failed'), 'error');
            }
            fileUploadInput.value = '';
        }
    });
};
