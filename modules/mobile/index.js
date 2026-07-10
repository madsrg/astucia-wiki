// Mobile layout controller.
//
// One signal drives everything: `state.isMobile` + a `body.mobile` class.
// It's computed from the user's display-mode override ('auto' | 'desktop' |
// 'mobile') and, when 'auto', the viewport width via matchMedia. All mobile
// styling keys off `body.mobile` (NOT a bare @media query) so a manual override
// works on any screen — a phone user can force the full desktop UI and a desktop
// user can preview mobile.
import { state } from '../core/state.js';
import { showToast } from '../core/utils.js';
import { t } from '../i18n/index.js';

const MOBILE_MQ = window.matchMedia('(max-width: 768px)');
const MODES = ['auto', 'desktop', 'mobile'];

const computeIsMobile = () => {
    if (state.displayMode === 'mobile')  return true;
    if (state.displayMode === 'desktop') return false;
    return MOBILE_MQ.matches; // 'auto'
};

const closeDrawer = () => {
    document.querySelector('.app-container')?.classList.remove('sidebar-mobile-open');
};

// Apply the current effective mode to the DOM. Mobile and desktop keep
// independent sidebar states: entering mobile drops the desktop "collapsed"
// class (the drawer has its own open/close), leaving mobile restores it.
const apply = () => {
    const isMobile  = computeIsMobile();
    state.isMobile  = isMobile;
    const container = document.querySelector('.app-container');
    document.body.classList.toggle('mobile', isMobile);

    if (isMobile) {
        container?.classList.remove('sidebar-collapsed');
    } else {
        closeDrawer();
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            container?.classList.add('sidebar-collapsed');
        }
    }
};

const setMode = (mode) => {
    state.displayMode = mode;
    localStorage.setItem('wiki_displayMode', mode);
    apply();
};

export const init = () => {
    apply();

    // Re-evaluate on viewport changes (only affects 'auto').
    MOBILE_MQ.addEventListener('change', apply);

    // Hamburger opens the off-canvas drawer; backdrop / picking a page closes it.
    const container   = document.querySelector('.app-container');
    const menuBtn     = document.getElementById('mobile-menu-btn');
    const backdrop    = document.getElementById('mobile-sidebar-backdrop');
    menuBtn?.addEventListener('click', () => container?.classList.toggle('sidebar-mobile-open'));
    backdrop?.addEventListener('click', closeDrawer);
    document.getElementById('file-navigator')?.addEventListener('click', (e) => {
        if (state.isMobile && e.target.closest('.file-item-content')) closeDrawer();
    });
    document.getElementById('file-browser')?.addEventListener('click', (e) => {
        if (state.isMobile && e.target.closest('.file-item-content')) closeDrawer();
    });

    // Display-mode toggle: cycles auto → desktop → mobile → auto.
    document.getElementById('display-mode-btn')?.addEventListener('click', () => {
        const next = MODES[(MODES.indexOf(state.displayMode) + 1) % MODES.length];
        setMode(next);
        showToast(t('mobile.mode-' + next), 'info');
    });
};
