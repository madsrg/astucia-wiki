import { api } from '../core/api.js';
import { displaySearchResults } from '../search/index.js';
import { t } from '../i18n/index.js';

export const init = () => {
    const mentionsBtn  = document.getElementById('mentions-btn');
    const commentsBtn  = document.getElementById('my-comments-btn');

    if (mentionsBtn) {
        mentionsBtn.addEventListener('click', async () => {
            const name = window.WIKI_USER_NAME || '';
            const uid  = window.WIKI_USER_UID  || 0;
            if (!name && !uid) return;
            const result = await api.call('get_mentions', { name, uid });
            if (result.success) displaySearchResults(t('mentions.my'), result.data);
        });
    }

    if (commentsBtn) {
        commentsBtn.addEventListener('click', async () => {
            const uid = window.WIKI_USER_UID || 0;
            if (!uid) return;
            const result = await api.call('get_my_comments', { uid });
            if (result.success) displaySearchResults(t('comments.my'), result.data);
        });
    }
};
