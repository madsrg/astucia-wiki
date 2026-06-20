import { showToast } from '../core/utils.js';
import { t } from '../i18n/index.js';

const closeLightbox = () => document.getElementById('help-lightbox').classList.add('hidden');

export const init = () => {
    const helpIcon = document.getElementById('help-icon');
    const helpLightbox = document.getElementById('help-lightbox');
    const lightboxCloseBtn = document.getElementById('lightbox-close-btn');
    const helpContentWrapper = document.getElementById('help-content-wrapper');

    helpIcon.addEventListener('click', async () => {
        try {
            const response = await fetch('help.htm');
            if (!response.ok) throw new Error(t('help.not-found'));
            helpContentWrapper.innerHTML = await response.text();
            helpLightbox.classList.remove('hidden');
        } catch (error) {
            showToast(error.message, 'error');
        }
    });

    lightboxCloseBtn.addEventListener('click', closeLightbox);
    helpLightbox.addEventListener('click', (e) => {
        if (e.target === helpLightbox) closeLightbox();
    });
};
