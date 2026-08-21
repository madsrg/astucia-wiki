// Astucia Wiki — Copyright (C) 2026 Mads Rotwitt
// Free software under the GNU GPL v3 or later. See LICENSE for the full notice,
// or <https://www.gnu.org/licenses/>. Distributed WITHOUT ANY WARRANTY.
/**
 * AstuciaWiki — entry point.
 *
 * This file only wires modules together and kicks off init.
 * Feature logic lives in modules/<feature>/index.js (and sub-files).
 *
 * Loaded as type="module" so it runs after DOM is ready (implicit defer)
 * and ES6 import/export work natively in the browser.
 */

import { icons } from './modules/core/icons.js';
import { init as initI18n, t } from './modules/i18n/index.js';
import { refreshFileTree, revealAndSelectFile, startTreePolling, stopTreePolling, init as initFileTree } from './modules/file_tree/index.js';
import { loadPage } from './modules/page_view/index.js';
import { init as initPageEdit } from './modules/page_edit/index.js';
import { init as initSearch } from './modules/page_edit/search.js';
import { init as initLinkLightbox } from './modules/page_edit/link_lightbox.js';
import { init as initHotkeys, loadHotkeys } from './modules/page_edit/hotkeys.js';
import { createEditorToolbar } from './modules/page_edit/toolbar.js';
import { createMobileEditorToolbar } from './modules/page_edit/mobile_toolbar.js';
import { init as initList } from './modules/list/index.js';
import { init as initTags } from './modules/tags/index.js';
import { init as initAttachments } from './modules/attachments/index.js';
import { init as initFileOps } from './modules/file_ops/index.js';
import { init as initNewItems } from './modules/new_items/index.js';
import { init as initGlobalSearch, generateTagCloud } from './modules/search/index.js';
import { init as initDiagram } from './modules/diagram/index.js';
import { init as initInsertMedia } from './modules/page_edit/insert_media.js';
import { init as initInsertComment } from './modules/page_edit/insert_comment.js';
import { init as initFilesFolder } from './modules/files_folder/index.js';
import { init as initAdmin } from './modules/admin/index.js';
import { init as initPreferences } from './modules/preferences/index.js';
import { init as initChat } from './modules/chat/index.js';
import { init as initChatSave } from './modules/chat_save/index.js';
import { init as initMobile } from './modules/mobile/index.js';
import { initSpaces, switchSpaceSilently, getAllSpaces } from './modules/spaces/index.js';
import { init as initMentions } from './modules/mentions/index.js';
import { init as initSession } from './modules/session/index.js';
import { init as initGit, checkSpaceGit } from './modules/git/index.js';
import { init as initNav, removeStaleRecentEntry } from './modules/nav/index.js';
import { init as initToc } from './modules/toc/index.js';
import { init as initPageChat } from './modules/page_chat/index.js';
import { init as initShare } from './modules/share/index.js';
import { init as initAdvancedSearch } from './modules/advanced_search/index.js';
import { init as initMcpExplorer } from './modules/mcp_explorer/index.js';
import { init as initGraph } from './modules/graph/index.js';
import { init as initSelectionActions } from './modules/selection_actions/index.js';
import { init as initTabs, onSpaceChange as tabsOnSpaceChange, resumeTarget as tabsResumeTarget } from './modules/tabs/index.js';
import { api } from './modules/core/api.js';
import { state } from './modules/core/state.js';

// --- Sidebar toggle ---
const initSidebarToggle = () => {
    const btn = document.getElementById('sidebar-toggle-btn');
    const container = document.querySelector('.app-container');
    const HK = ' (S)'; // shortcut hint shown in the tooltip
    const applyTitle = (collapsed) => { btn.title = (collapsed ? t('nav.expand') : t('nav.collapse')) + HK; };

    const startCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
    if (startCollapsed) { container.classList.add('sidebar-collapsed'); btn.innerHTML = '&#x203A;'; }
    applyTitle(startCollapsed);

    const toggle = () => {
        const collapsed = container.classList.toggle('sidebar-collapsed');
        btn.innerHTML = collapsed ? '&#x203A;' : '&#x2039;';
        applyTitle(collapsed);
        localStorage.setItem('sidebarCollapsed', collapsed);
    };
    btn.addEventListener('click', toggle);

    // Press "s" to toggle the sidebar while reading any page (not while the
    // Markdown editor is open, and not when typing in a field).
    document.addEventListener('keydown', (e) => {
        if (e.key !== 's' || e.ctrlKey || e.metaKey || e.altKey || e.shiftKey) return;
        if (state.isEditing) return;
        const el = document.activeElement, tag = el?.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el?.isContentEditable) return;
        e.preventDefault();
        toggle();
    });
};

// --- Pane tabs ---
const initPaneTabs = () => {
    const pagesTab = document.querySelector('.pane-tab[data-pane="pages"]');

    const setNavMode = (mode) => {
        const isFolder = mode === 'folder';
        pagesTab.dataset.nav = mode;
        pagesTab.setAttribute('data-i18n', isFolder ? 'nav.pane-folder' : 'nav.pane-tree');
        pagesTab.textContent = t(isFolder ? 'nav.pane-folder' : 'nav.pane-tree');
        document.getElementById('file-navigator').classList.toggle('hidden', isFolder);
        document.getElementById('file-browser').classList.toggle('hidden', !isFolder);
    };

    document.querySelectorAll('.pane-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            if (tab === pagesTab && tab.classList.contains('active')) {
                setNavMode(tab.dataset.nav === 'folder' ? 'tree' : 'folder');
                return;
            }
            document.querySelectorAll('.pane-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            document.querySelectorAll('.pane-content').forEach(p => p.classList.remove('active'));
            document.getElementById(`${tab.dataset.pane}-pane`).classList.add('active');
        });
    });
};

// --- New-item dropdown icons ---
const populateDropdownIcons = () => {
    document.getElementById('dropdown-new-page').innerHTML = icons.file + ' ' + t('nav.new-page');
    document.getElementById('dropdown-new-folder').innerHTML = icons.folder + ' ' + t('nav.new-folder');
    document.getElementById('dropdown-new-filesfolder').innerHTML = icons.filesFolder + ' ' + t('nav.new-files-lib');
    document.getElementById('dropdown-new-diagram').innerHTML = icons.diagram + ' ' + t('nav.new-diagram');
    document.getElementById('dropdown-new-list').innerHTML = icons.list + ' ' + t('nav.new-list');
    document.getElementById('dropdown-new-chat').innerHTML = icons.chat + ' ' + t('nav.new-chat');
    document.getElementById('dropdown-new-search').innerHTML = icons.search + ' ' + t('nav.new-search');
};

// --- Boot ---
const init = async () => {
    initI18n();
    window.addEventListener('wiki:languagechange', populateDropdownIcons);
    populateDropdownIcons();
    initSidebarToggle();
    initMobile();
    initPaneTabs();

    // Capture pageid before initSpaces wipes it from the URL
    const _initialPageId = new URLSearchParams(window.location.search).get('pageid');

    // Initialise spaces first — sets state.currentSpace before any file/page loads
    await initSpaces({
        onSpaceChange: async () => {
            await checkSpaceGit();
            await refreshFileTree();
            // Each space keeps its own tabs, so re-entering one reopens the page it was
            // left on rather than bouncing back to its start page.
            const resume = tabsOnSpaceChange();
            if (resume) {
                await loadPage(resume.path, resume.id, resume.tags, { intent: 'permanent' });
                revealAndSelectFile(resume.path);
            } else {
                await loadStartPage();
            }
            startTreePolling(state.currentSpace);
        },
    });
    await checkSpaceGit();

    // Wire file tree: inject loadPage callback to avoid circular imports
    initFileTree({ onLoadPage: loadPage, onGenerateTagCloud: generateTagCloud });

    // Init all feature modules
    initPageEdit();
    initSearch();
    initLinkLightbox();
    await loadHotkeys();
    initHotkeys();
    createEditorToolbar();
    createMobileEditorToolbar();
    initList();
    initTags();
    initAttachments();
    initFileOps();
    initNewItems();
    initGlobalSearch();
    initDiagram();
    initInsertMedia();
    initInsertComment();
    initFilesFolder();
    initAdmin();
    initPreferences();
    initChat();
    initChatSave();
    initGit();
    initMentions();
    initSession();
    initToc();
    initPageChat();
    initShare();
    initAdvancedSearch();
    initMcpExplorer();
    initSelectionActions();
    initGraph({ onNavigate: navigateToPageId });
    initNav({
        onNavigate: async (id, space, path) => {
            const spaceIsValid = !space || getAllSpaces().includes(space);
            if (space && space !== state.currentSpace && spaceIsValid) {
                switchSpaceSilently(space);
                await checkSpaceGit();
                await refreshFileTree();
                startTreePolling(state.currentSpace);
            } else if (space && !spaceIsValid && path) {
                // Space name is stale (e.g. renamed before client-side fix existed);
                // remove it from recents so it doesn't confuse future sessions.
                removeStaleRecentEntry(path, space);
            }
            await navigateToPageId(id, path);
        },
        onRoot: loadStartPage,
    });

    // Load file tree (also generates tag cloud), then start background polling
    await refreshFileTree();
    startTreePolling(state.currentSpace);

    // After the tree, because restoring persisted tabs means dropping the ones whose
    // file has since been deleted — which needs state.fullFileTree. Also before the
    // first loadPage below, since that is what puts the opening page in the tab bar.
    initTabs();

    // Navigate to page from URL param, otherwise reopen the last tab, otherwise the
    // start page. An explicit ?pageid= always wins — it is a deliberate destination.
    if (_initialPageId) {
        await navigateToPageId(_initialPageId);
    } else {
        const resume = tabsResumeTarget();
        if (resume) {
            await loadPage(resume.path, resume.id, resume.tags, { intent: 'permanent' });
            revealAndSelectFile(resume.path);
        } else {
            await loadStartPage();
        }
    }

    // Intercept clicks on internal ?pageid= links in the viewer for SPA navigation.
    // This avoids full page reloads and handles cross-space links correctly.
    document.getElementById('viewer-content').addEventListener('click', async (e) => {
        const a = e.target.closest('a[href]');
        if (!a) return;
        const href = a.getAttribute('href') || '';

        // A bare fragment — from `[[#Heading]]` or a hand-written anchor. The scroller is
        // #viewer-content, not the window, so the browser's own fragment jump does the wrong
        // thing and has to be replaced.
        if (href.startsWith('#')) {
            e.preventDefault();
            scrollToAnchor(href.slice(1));
            return;
        }
        if (!href.includes('pageid=')) return;
        e.preventDefault();
        // Split the fragment off before parsing: `?pageid=5&space=Docs#heading` would otherwise
        // yield a space of "Docs#heading" and fail to switch space.
        const [query, hash] = href.replace(/^\?/, '').split('#');
        const params = new URLSearchParams(query);
        const linkedPageId = params.get('pageid');
        const linkedSpace  = params.get('space');
        if (!linkedPageId) return;
        if (linkedSpace && linkedSpace !== state.currentSpace) {
            switchSpaceSilently(linkedSpace);
            await checkSpaceGit();
            await refreshFileTree();
            startTreePolling(state.currentSpace);
        }
        await navigateToPageId(linkedPageId);
        if (hash) scrollToAnchor(hash);      // `[[Page#Heading]]` lands on the heading
    });

    document.getElementById('logo-btn').addEventListener('click', loadStartPage);
};

/**
 * Scroll a heading into view inside the viewer.
 *
 * Ids come from addHeadingIds via toc's headingSlug, and a wikilink builds its fragment with
 * the same function, so the two always agree. Encoded because a slug can contain characters
 * that had to be percent-encoded into the href.
 */
const scrollToAnchor = (rawSlug) => {
    const viewer = document.getElementById('viewer-content');
    if (!viewer || !rawSlug) return;
    let slug = rawSlug;
    try { slug = decodeURIComponent(rawSlug); } catch { /* keep it as-is */ }
    const target = viewer.querySelector(`#${CSS.escape(slug)}`);
    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

const findItemByPath = (items, path) => {
    for (const item of items) {
        if (item.path === path) return item;
        if (item.children) { const found = findItemByPath(item.children, path); if (found) return found; }
    }
    return null;
};

const navigateToPageId = async (pageId, fallbackPath = null) => {
    const result = await api.call('get_path_from_id', { pageid: pageId });
    if (result.success && result.path) {
        // Backend found the page in a different space — switch there first.
        if (result.space && result.space !== state.currentSpace) {
            switchSpaceSilently(result.space);
            await checkSpaceGit();
            await refreshFileTree();
            startTreePolling(state.currentSpace);
        }
        const listResult = await api.call('list');
        const item = findItemByPath(listResult.data || [], result.path);
        await loadPage(result.path, pageId, item?.tags || []);
        revealAndSelectFile(result.path);
    } else if (fallbackPath) {
        // Stale ID (e.g. after a reindex) — try loading by the stored path instead.
        const listResult = await api.call('list');
        const item = findItemByPath(listResult.data || [], fallbackPath);
        if (item) {
            await loadPage(item.path, item.id, item.tags || []);
            revealAndSelectFile(item.path);
        } else {
            const { showToast } = await import('./modules/core/utils.js');
            showToast('Could not find the linked page.', 'error');
        }
    } else {
        const { showToast } = await import('./modules/core/utils.js');
        showToast('Could not find the linked page.', 'error');
    }
};

const loadStartPage = async () => {
    const result = await api.call('get_start_page');
    if (!result.success) return;
    // No Main.md in this space — show an empty view rather than creating one.
    if (!result.path) {
        const { showBlankPage } = await import('./modules/page_view/index.js');
        await showBlankPage();
        return;
    }
    await loadPage(result.path, result.id, []);
    revealAndSelectFile(result.path);
};

init();
