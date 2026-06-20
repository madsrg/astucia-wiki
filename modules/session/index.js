import { api } from '../core/api.js';
import { state } from '../core/state.js';
import { t } from '../i18n/index.js';

const WARN_BEFORE = 120; // seconds before expiry to show the warning

export const init = () => {
    const timeout = window.WIKI_SESSION_TIMEOUT;
    if (!timeout) return; // auth disabled or not configured

    const banner   = document.getElementById('session-warning');
    const text     = document.getElementById('session-warning-text');
    const stayBtn  = document.getElementById('session-stay-btn');
    if (!banner) return;

    let warningVisible = false;

    const idleSeconds = () => Math.floor((Date.now() - state.lastApiCallTime) / 1000);

    const hideBanner = () => {
        warningVisible = false;
        banner.classList.add('hidden');
    };

    const ping = async () => {
        await api.call('ping');
        hideBanner();
    };

    stayBtn?.addEventListener('click', ping);

    // Any user activity while the banner is visible auto-pings the server.
    ['mousemove', 'keydown', 'click', 'touchstart'].forEach(ev => {
        document.addEventListener(ev, () => { if (warningVisible) ping(); }, { passive: true });
    });

    setInterval(() => {
        const remaining = timeout - idleSeconds();

        if (remaining <= 0) {
            window.location.href = 'login.php';
            return;
        }

        if (remaining <= WARN_BEFORE) {
            warningVisible = true;
            banner.classList.remove('hidden');
            const m = Math.floor(remaining / 60);
            const s = remaining % 60;
            text.textContent = t('session.warning', { time: `${m}:${String(s).padStart(2, '0')}` });
        } else {
            if (warningVisible) hideBanner();
        }
    }, 1000);
};
