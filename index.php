<?php
require_once 'config.php';
require_once 'mailer.php';
session_start();

// Anonymous access: allow unauthenticated visitors as readers when the option is enabled.
$isAnonymous = AUTHENTICATION_ENABLED && defined('ANONYMOUS_ACCESS_ENABLED') && ANONYMOUS_ACCESS_ENABLED && !isset($_SESSION['user']);

if (AUTHENTICATION_ENABLED && !$isAnonymous && !isset($_SESSION['user'])) {
    $_SESSION['login_redirect'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit;
}

$userRole     = $isAnonymous ? 'reader' : ((AUTHENTICATION_ENABLED && isset($_SESSION['user'])) ? ($_SESSION['user']['role']  ?? 'editor') : 'admin');
$userFont     = (AUTHENTICATION_ENABLED && isset($_SESSION['user'])) ? ($_SESSION['user']['fontFamily'] ?? 'sans') : 'sans';
$userFontSize = (AUTHENTICATION_ENABLED && isset($_SESSION['user'])) ? ($_SESSION['user']['fontSize']   ?? '11pt') : '11pt';
if (!in_array($userFont,     ['sans','serif','mono']))                            $userFont     = 'sans';
if (!in_array($userFontSize, ['10pt','11pt','12pt','14pt','16pt']))               $userFontSize = '11pt';
$mailConfigured = is_mail_configured() ? '1' : '0';
$currentUserUid  = (AUTHENTICATION_ENABLED && isset($_SESSION['user'])) ? (int)($_SESSION['user']['uid'] ?? 0) : 0;
$currentUserName = (AUTHENTICATION_ENABLED && isset($_SESSION['user'])) ? htmlspecialchars($_SESSION['user']['name'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="en" data-font="<?php echo htmlspecialchars($userFont); ?>" data-font-size="<?php echo htmlspecialchars($userFontSize); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_TITLE; ?></title>
    
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="styles.css?v=<?php echo filemtime(__DIR__ . '/styles.css'); ?>">
</head>
<body class="role-<?php echo htmlspecialchars($userRole); ?>" data-mail-configured="<?php echo $mailConfigured; ?>" data-user-uid="<?php echo $currentUserUid; ?>" data-user-name="<?php echo $currentUserName; ?>">

    <?php if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production'): ?>
    <div class="env-banner env-banner-<?php echo htmlspecialchars(ENVIRONMENT); ?>">
        <?php echo strtoupper(htmlspecialchars(ENVIRONMENT)); ?> ENVIRONMENT — changes here are not live
    </div>
    <?php endif; ?>

    <div class="app-container">
        <div id="toc-panel" class="toc-panel">
            <div class="toc-panel-header">
                <span class="toc-panel-title" data-i18n="toc.panel-title">Contents</span>
                <button id="toc-panel-close-btn" class="toc-panel-close-btn">&times;</button>
            </div>
            <nav id="toc-panel-nav" class="toc-panel-nav"></nav>
        </div>
        <div id="page-chat-panel" class="page-chat-panel">
            <div class="pc-panel-header">
                <span class="pc-panel-title" id="pc-panel-title" data-i18n="page-chat.panel-title">Page Chat</span>
                <button id="pc-close-btn" class="pc-close-btn" title="Close">&times;</button>
            </div>
            <div id="pc-messages" class="pc-messages"></div>
        </div>
        <aside class="sidebar">
            <button id="sidebar-toggle-btn" class="sidebar-toggle-btn" data-i18n-title="nav.collapse" title="Collapse sidebar">&#x2039;</button>
            <div class="sidebar-header">
                <div class="logo-wrapper" id="logo-btn" title="Go to start page" style="cursor:pointer;">
                    <img src="logo.png" alt="Wiki Logo" class="sidebar-logo">
                </div>
                <div class="title-bar">
                    <h1><?php echo APP_TITLE; ?></h1>
                </div>
                <div class="sidebar-actions">
                    <div class="dropdown-container">
                        <button id="new-item-btn" class="btn btn-blue" data-i18n="nav.new-btn">New …</button>
                        <div id="new-item-dropdown" class="dropdown-content hidden">
                            <a href="#" id="dropdown-new-page"></a>
                            <a href="#" id="dropdown-new-folder"></a>
                            <a href="#" id="dropdown-new-filesfolder"></a>
                            <a href="#" id="dropdown-new-diagram"></a>
                            <a href="#" id="dropdown-new-list"></a>
                            <a href="#" id="dropdown-new-chat"></a>
                            <a href="#" id="dropdown-new-search"></a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="sidebar-panes">
                <div class="pane-tabs">
                    <button class="pane-tab active" data-pane="pages" data-nav="tree" data-i18n="nav.pane-tree">Tree</button>
                    <button class="pane-tab" data-pane="tags" data-i18n="nav.pane-search">Search</button>
                    <button class="pane-tab" data-pane="recent" data-i18n-title="nav.pane-recent" title="Recent">Recent</button>
                    <button class="pane-tab" data-pane="saved" data-i18n="nav.pane-saved">★</button>
                </div>
                <div id="pages-pane" class="pane-content active">
                    <nav id="file-navigator"></nav>
                    <nav id="file-browser" class="hidden"></nav>
                </div>
                <div id="tags-pane" class="pane-content">
                    <div class="search-form">
                        <input type="text" id="search-query-input" data-i18n-placeholder="nav.search-ph" placeholder="Search pages…">
                        <button id="search-query-btn" class="btn btn-sm btn-secondary" data-i18n="nav.search-btn">Search</button>
                    </div>
                    <div id="search-all-spaces-row" class="search-all-spaces-row hidden">
                        <label class="search-all-spaces-label">
                            <input type="checkbox" id="search-all-spaces-chk">
                            <span data-i18n="search.all-spaces">All spaces</span>
                        </label>
                    </div>
                    <div id="tag-cloud"></div>
                </div>
                <div id="recent-pane" class="pane-content"></div>
                <div id="saved-pane" class="pane-content"></div>
            </div>
            <div class="sidebar-footer">
                <div id="space-switcher" class="space-switcher"></div>
                <?php if ($isAnonymous): ?>
                <div class="user-info">
                    <a href="auth.php" class="btn btn-sm btn-secondary" data-i18n="nav.login">Login</a>
                </div>
                <?php elseif (AUTHENTICATION_ENABLED && isset($_SESSION['user'])): ?>
                <div class="user-info">
                    <span class="user-info-name"><?php echo htmlspecialchars($_SESSION['user']['name']); ?></span>
                    <span class="user-role-badge role-badge-<?php echo htmlspecialchars($userRole); ?>"><?php echo htmlspecialchars($userRole); ?></span>
                    <a href="auth.php?action=logout" class="btn btn-sm btn-secondary" data-i18n="nav.logout">Logout</a>
                </div>
                <?php endif; ?>
                <div class="sidebar-footer-icons">
                    <?php if (AUTHENTICATION_ENABLED && isset($_SESSION['user'])): ?>
                    <button id="preferences-btn" class="sidebar-icon-btn" data-i18n-title="nav.prefs-title" title="My Preferences">
                        <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </button>
                    <?php endif; ?>
                    <?php if (AUTHENTICATION_ENABLED && isset($_SESSION['user'])): ?>
                    <button id="mentions-btn" class="sidebar-icon-btn" data-i18n-title="nav.mentions-title" title="My Mentions — pages where I am mentioned">
                        <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94"/></svg>
                    </button>
                    <button id="my-comments-btn" class="sidebar-icon-btn" data-i18n-title="nav.comments-title" title="My Comments — pages I commented on">
                        <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    </button>
                    <?php endif; ?>
                    <?php if ($userRole === 'admin' || $userRole === 'editor'): ?>
                    <button id="mcp-explorer-btn" class="sidebar-icon-btn" data-i18n-title="explorer.btn-title" title="MCP Tool Explorer">
                        <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="9" y="14" width="6" height="6" rx="1"/><path d="M7 10v2a2 2 0 0 0 2 2h1"/><path d="M17 10v2a2 2 0 0 1-2 2h-1"/></svg>
                    </button>
                    <?php endif; ?>
                    <?php if ($userRole === 'admin'): ?>
                    <button id="admin-btn" class="sidebar-icon-btn" data-i18n-title="nav.admin-title" title="Administration">
                        <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                    <?php endif; ?>
                    <button id="display-mode-btn" class="sidebar-icon-btn" data-i18n-title="mobile.toggle-title" title="Desktop / mobile view">
                        <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="14" height="10" rx="1"/><line x1="2" y1="17" x2="12" y2="17"/><rect x="17" y="9" width="5" height="11" rx="1"/></svg>
                    </button>
                    <div class="lang-selector-wrapper">
                        <button id="lang-btn" class="sidebar-icon-btn" data-i18n-title="nav.lang-title" title="Language">
                            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                        </button>
                        <div id="lang-dropdown" class="lang-dropdown hidden">
                            <button class="lang-option" data-lang="en">🇬🇧 English</button>
                            <button class="lang-option" data-lang="da">🇩🇰 Dansk</button>
                            <button class="lang-option" data-lang="sv">🇸🇪 Svenska</button>
                            <button class="lang-option" data-lang="es">🇪🇸 Español</button>
                            <button class="lang-option" data-lang="fr">🇫🇷 Français</button>
                            <button class="lang-option" data-lang="de">🇩🇪 Deutsch</button>
                        </div>
                    </div>
                </div>
            </div>
        </aside>

        <div id="mobile-sidebar-backdrop" class="mobile-sidebar-backdrop"></div>

        <main class="main-content">
            <header class="main-header">
                <button id="mobile-menu-btn" class="mobile-menu-btn" data-i18n-title="mobile.menu" title="Menu" aria-label="Menu">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <div class="page-title-wrapper">
                    <nav id="page-breadcrumb" class="page-breadcrumb hidden"></nav>
                    <div class="page-title-row">
                        <h2 id="current-page-title" data-i18n="header.select-page">Select a page to start</h2>
                        <button id="favorite-btn" class="btn btn-icon btn-fav hidden" title="Add to saved">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        </button>
                        <span id="page-id-display" class="page-id-badge hidden"></span>
                    </div>
                </div>
                <div class="header-actions">
                    <div id="page-actions-group" class="page-actions-group hidden">
                        <div class="file-actions-dropdown">
                            <button id="file-actions-menu-btn" class="btn btn-icon btn-secondary" title="File actions">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></svg>
                            </button>
                            <div id="file-actions-menu" class="file-actions-menu hidden">
                                <button id="copy-btn" class="file-actions-menu-item" data-i18n-title="header.copy">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                    <span data-i18n="header.copy">Copy</span>
                                </button>
                                <button id="move-btn" class="file-actions-menu-item" data-i18n-title="header.move">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="5 9 2 12 5 15"/><polyline points="9 5 12 2 15 5"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="12" y1="2" x2="12" y2="22"/></svg>
                                    <span data-i18n="header.move">Move</span>
                                </button>
                                <button id="rename-btn" class="file-actions-menu-item" data-i18n-title="header.rename">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    <span data-i18n="header.rename">Rename</span>
                                </button>
                                <button id="backlinks-btn" class="file-actions-menu-item" data-i18n-title="header.backlinks">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                    <span data-i18n="header.backlinks">Backlinks</span>
                                </button>
                                <button id="print-btn" class="file-actions-menu-item" data-i18n-title="header.print">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                    <span data-i18n="header.print">Print</span>
                                </button>
                                <div class="file-actions-menu-sep"></div>
                                <button id="delete-btn" class="file-actions-menu-item file-actions-menu-item-danger" data-i18n-title="header.delete">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                    <span data-i18n="header.delete">Delete</span>
                                </button>
                            </div>
                        </div>
                        <button id="git-history-btn" class="btn btn-icon btn-secondary hidden" data-i18n-title="header.history" title="Version History">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </button>
                        <button id="git-commit-toggle-btn" class="btn btn-icon btn-secondary hidden" data-i18n-title="git.tracking-off" title="Git tracking OFF — click to enable">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="3" x2="6" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/></svg>
                        </button>
                        <button id="git-snapshot-btn" class="btn btn-icon btn-secondary hidden" data-i18n-title="git.snapshot-title" title="Commit snapshot">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><line x1="2" y1="12" x2="8" y2="12"/><line x1="16" y1="12" x2="22" y2="12"/></svg>
                        </button>
                    </div>
                    <button id="search-btn" class="btn btn-icon btn-secondary hidden" data-i18n-title="header.search-replace" title="Search & Replace (Alt+F)">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </button>
                    <button id="chat-topic-btn" class="btn btn-icon btn-secondary hidden" data-i18n-title="header.chat-topic" title="Edit topic">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2"/></svg>
                    </button>
                    <button id="diagram-edit-btn" class="btn btn-icon btn-blue hidden" data-i18n-title="header.diagram-edit" title="Edit diagram">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg>
                    </button>
                    <button id="page-chat-btn" class="btn btn-icon btn-secondary hidden" data-i18n-title="page-chat.open-btn" title="Page Chat">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </button>
                    <button id="toc-btn" class="btn btn-icon btn-secondary hidden" data-i18n-title="toc.show-btn" title="Table of Contents">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="5" x2="21" y2="5"/><line x1="7" y1="9" x2="21" y2="9"/><line x1="7" y1="13" x2="21" y2="13"/><line x1="3" y1="17" x2="21" y2="17"/><line x1="7" y1="21" x2="21" y2="21"/></svg>
                    </button>
                    <?php if ($mailConfigured === '1'): ?>
                    <button id="share-btn" class="btn btn-icon btn-secondary hidden" data-i18n-title="header.share" title="Share page">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </button>
                    <?php endif; ?>
                    <button id="edit-btn" class="btn btn-icon btn-blue" data-i18n-title="header.edit" title="Edit (e)" disabled>
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg>
                    </button>
                    <button id="cancel-btn" class="btn btn-icon btn-secondary hidden" data-i18n-title="header.cancel" title="Cancel">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                    <button id="save-btn" class="btn btn-icon btn-green hidden" data-i18n-title="header.save" title="Save (Alt+S)" disabled>
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    </button>
                </div>
            </header>
            <div class="editor-wrapper">
                <div id="viewer-container" class="viewer-wrapper">
                    <div id="viewer-content"></div>
                    <div id="diagram-viewer" class="hidden"></div>
                    <div id="page-meta-row" class="page-meta-row hidden">
                        <div id="attachments-section" class="attachments-section">
                            <div id="attachment-list"></div>
                            <button id="attach-file-btn" class="btn btn-secondary btn-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                                <span data-i18n="attach.attach-file">Attach File</span>
                            </button>
                            <input type="file" id="file-upload-input" class="hidden">
                        </div>
                        <div id="tags-container" class="tags-section">
                            <div id="tags-display"></div>
                            <div class="tag-input-wrap">
                                <input type="text" id="tag-input" data-i18n-placeholder="tags.placeholder" placeholder="Add a tag…" />
                            </div>
                        </div>
                    </div>
                    <div id="pc-page-input-area" class="chat-input-area hidden">
                        <div id="pc-emoji-picker" class="chat-emoji-picker hidden"></div>
                        <div id="pc-mention-popup" class="chat-mention-popup hidden"></div>
                        <button id="pc-emoji-btn" class="btn btn-icon btn-secondary chat-emoji-btn" data-i18n-title="chat.emoji-title" title="Emoji">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
                        </button>
                        <textarea id="pc-input" class="chat-input" rows="1" data-i18n-placeholder="chat.placeholder" placeholder="Type a message… (Enter to send, Shift+Enter for new line, # to mention)"></textarea>
                        <button id="pc-send-btn" class="btn btn-blue chat-send-btn" data-i18n="chat.send">Send</button>
                    </div>
                </div>
                <div class="editor-container-wrapper hidden">
                    <div id="editor-toolbar"></div>
                    <div class="editor-area">
                        <div id="line-indicator"></div><textarea id="editor-container"></textarea>
                    </div>
                    <div id="search-replace-bar" class="search-replace-bar hidden">
                        <input type="text" id="search-input" data-i18n-placeholder="search.find-ph" placeholder="Find">
                        <input type="text" id="replace-input" data-i18n-placeholder="search.replace-ph" placeholder="Replace">
                        <button id="replace-btn" class="btn btn-secondary btn-sm" data-i18n="search.replace-btn">Replace</button>
                        <button id="replace-all-btn" class="btn btn-secondary btn-sm" data-i18n="search.replace-all">Replace All</button>
                        <button id="search-close-btn" class="search-close-btn">&times;</button>
                    </div>
                </div>
                <div id="files-folder-container" class="viewer-wrapper hidden">
                    <div class="ff-actions">
                        <button id="ff-upload-btn" class="btn btn-blue btn-sm">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                            <span data-i18n="files.upload-btn">Upload</span>
                        </button>
                        <input type="file" id="ff-upload-input" class="hidden" multiple>
                        <div class="ff-view-modes">
                            <button id="ff-view-simple" class="btn btn-sm btn-blue" data-i18n-title="files.view-simple" title="Simple list">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                            </button>
                            <button id="ff-view-detailed" class="btn btn-sm btn-secondary" data-i18n-title="files.view-detailed" title="Detailed list">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="4" rx="1"/><rect x="3" y="10" width="18" height="4" rx="1"/><rect x="3" y="17" width="18" height="4" rx="1"/></svg>
                            </button>
                            <button id="ff-view-icons" class="btn btn-sm btn-secondary" data-i18n-title="files.view-icons" title="Icon grid">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                            </button>
                        </div>
                    </div>
                    <div id="ff-file-list"></div>
                </div>
                <div id="list-view-container" class="viewer-wrapper hidden">
                    <div class="list-actions">
                        <button id="add-item-btn" class="btn btn-icon btn-blue" data-i18n-title="list.add-item" title="Add Item">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        </button>
                        <button id="view-settings-btn" class="btn btn-icon btn-secondary" data-i18n-title="list.view-settings" title="View Settings">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="8" cy="6" r="2" fill="currentColor" stroke="none"/><circle cx="16" cy="12" r="2" fill="currentColor" stroke="none"/><circle cx="10" cy="18" r="2" fill="currentColor" stroke="none"/></svg>
                        </button>
                        <button id="list-props-btn" class="btn btn-icon btn-secondary" data-i18n-title="list.props-btn" title="List Properties">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 9v12"/></svg>
                        </button>
                         <div class="dropdown-container" id="export-dropdown-container">
                            <button id="export-btn" class="btn btn-icon btn-secondary" data-i18n-title="list.export" title="Export List">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                            </button>
                            <div id="export-dropdown" class="dropdown-content hidden">
                                <a href="#" id="export-json" data-i18n="list.export-json">as JSON</a>
                                <a href="#" id="export-xml" data-i18n="list.export-xml">as XML</a>
                                <a href="#" id="export-csv" data-i18n="list.export-csv">as CSV</a>
                            </div>
                        </div>
                        <button id="add-column-btn" class="btn btn-icon btn-secondary" data-i18n-title="list.add-column" title="Add Column">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="18" rx="1"/><rect x="14" y="3" width="7" height="18" rx="1" stroke-dasharray="3 2"/><line x1="17.5" y1="8" x2="17.5" y2="14"/><line x1="14.5" y1="11" x2="20.5" y2="11"/></svg>
                        </button>
                    </div>
                    <div id="view-tabs" class="view-tabs hidden"></div>
                    <div id="list-items-table" class="list-table-wrapper"></div>
                </div>
                <div id="chat-view-container" class="viewer-wrapper hidden">
                    <div id="chat-topic-bar" class="chat-topic-bar hidden">
                        <span id="chat-topic-text"></span>
                    </div>
                    <div id="chat-sticky-area" class="chat-sticky-area hidden"></div>
                    <div id="chat-messages" class="chat-messages"></div>
                    <div class="chat-input-area">
                        <div id="chat-emoji-picker" class="chat-emoji-picker hidden"></div>
                        <div id="chat-mention-popup" class="chat-mention-popup hidden"></div>
                        <button id="chat-emoji-btn" class="btn btn-icon btn-secondary chat-emoji-btn" data-i18n-title="chat.emoji-title" title="Emoji">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
                        </button>
                        <textarea id="chat-input" class="chat-input" data-i18n-placeholder="chat.placeholder" placeholder="Type a message… (Enter to send, Shift+Enter for new line, # to mention)" rows="1"></textarea>
                        <button id="chat-send-btn" class="btn btn-blue chat-send-btn" data-i18n="chat.send">Send</button>
                    </div>
                </div>
                <div id="search-view-container" class="viewer-wrapper hidden">
                    <div id="adv-search-results" class="adv-search-results"></div>
                    <div class="adv-search-input-area">
                        <div id="adv-search-help" class="adv-search-help hidden"></div>
                        <div id="adv-search-mention-popup" class="chat-mention-popup hidden"></div>
                        <div class="adv-search-controls">
                            <div id="adv-search-sources" class="adv-search-sources"></div>
                            <button id="adv-search-help-btn" class="btn btn-icon btn-secondary" data-i18n-title="asearch.help-title" title="Query syntax help">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17" stroke-width="3"/></svg>
                            </button>
                        </div>
                        <div class="adv-search-input-row">
                            <textarea id="adv-search-input" class="chat-input" data-i18n-placeholder="asearch.placeholder" placeholder="Search… e.g. onboarding tag:hr updated:30d" rows="1"></textarea>
                            <button id="adv-search-run-btn" class="btn btn-blue" data-i18n="asearch.run">Search</button>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="mcp-explorer-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content mcp-explorer-content">
            <button id="mcp-explorer-close" class="lightbox-close">&times;</button>
            <h3 style="margin:0 0 .25rem"><span data-i18n="explorer.title">MCP Tool Explorer</span></h3>
            <p class="form-hint" style="margin:0 0 .75rem" data-i18n="explorer.subtitle">Browse and invoke tools on a registered MCP server.</p>
            <div id="mcp-explorer-results" class="mcp-explorer-results"></div>
            <div class="mcp-explorer-input-area">
                <div class="adv-search-controls">
                    <select id="mcp-explorer-server" class="form-control"></select>
                    <input id="mcp-explorer-tool-filter" class="form-control mcp-tool-filter" type="text" data-i18n-placeholder="explorer.filter-placeholder" placeholder="Filter tools…">
                    <select id="mcp-explorer-tool" class="form-control"></select>
                </div>
                <div id="mcp-explorer-help" class="mcp-explorer-help"></div>
                <div id="mcp-explorer-args" class="mcp-explorer-args"></div>
                <div class="adv-search-input-row" style="justify-content:flex-end">
                    <button id="mcp-explorer-clear" class="btn btn-secondary" data-i18n="explorer.clear">Clear</button>
                    <button id="mcp-explorer-run" class="btn btn-blue" data-i18n="explorer.invoke">Invoke</button>
                </div>
            </div>
        </div>
    </div>

    <div id="mcp-save-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content input-modal-content">
            <div class="input-modal-header">
                <h3 data-i18n="explorer.save-title">Save result as page</h3>
            </div>
            <div class="form-group">
                <label class="mcp-save-label" for="mcp-save-folder" data-i18n="explorer.save-folder">Folder</label>
                <select id="mcp-save-folder" class="form-control"></select>
            </div>
            <div class="form-group">
                <label class="mcp-save-label" for="mcp-save-name" data-i18n="explorer.save-name">Page name</label>
                <input type="text" id="mcp-save-name" class="form-control" autocomplete="off">
            </div>
            <div class="lightbox-footer">
                <button id="mcp-save-close" class="btn btn-secondary" data-i18n="btn.cancel">Cancel</button>
                <button id="mcp-save-confirm" class="btn btn-blue" data-i18n="explorer.save-confirm">Save</button>
            </div>
        </div>
    </div>

    <div id="adv-remote-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content adv-remote-content">
            <button id="adv-remote-close" class="lightbox-close">&times;</button>
            <div class="adv-remote-header">
                <span id="adv-remote-source" class="adv-turn-source"></span>
                <h3 id="adv-remote-title" style="margin:0"></h3>
            </div>
            <div id="adv-remote-body" class="adv-remote-body"></div>
            <div class="lightbox-footer">
                <button id="adv-remote-save" class="btn btn-blue" data-i18n="asearch.save-copy">Save local copy</button>
            </div>
        </div>
    </div>

    <div id="save-page-picker" class="lightbox-overlay hidden">
        <div class="lightbox-content input-modal-content">
            <div class="input-modal-header">
                <h3 id="save-page-picker-title" data-i18n="savepage.title">Save local copy</h3>
            </div>
            <div class="form-group">
                <label class="mcp-save-label" for="save-page-picker-folder" data-i18n="savepage.folder">Folder</label>
                <select id="save-page-picker-folder" class="form-control"></select>
            </div>
            <div class="form-group">
                <label class="mcp-save-label" for="save-page-picker-name" data-i18n="savepage.name">Page name</label>
                <input type="text" id="save-page-picker-name" class="form-control" autocomplete="off">
            </div>
            <div class="lightbox-footer">
                <button id="save-page-picker-cancel" class="btn btn-secondary" data-i18n="btn.cancel">Cancel</button>
                <button id="save-page-picker-ok" class="btn btn-blue" data-i18n="savepage.save">Save</button>
            </div>
        </div>
    </div>

    <div id="page-chat-confirm-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content lightbox-content-sm" style="padding:1.5rem">
            <button id="pcl-close-btn" class="lightbox-close">&times;</button>
            <h3 style="margin:0 0 1rem" data-i18n="page-chat.create-title">Create Page Chat</h3>
            <p style="margin:0 0 .65rem">A chat named <strong id="pcl-chat-name"></strong> will be created in the same folder as this page.</p>
            <p style="margin:0 0 1.5rem;font-size:.85rem;color:var(--text-muted)" data-i18n="page-chat.create-hint">When you mention an AI user in this chat, the linked page content is automatically used as context for its reply.</p>
            <div class="lightbox-footer">
                <button id="pcl-cancel-btn" class="btn btn-secondary" data-i18n="btn.cancel">Cancel</button>
                <button id="pcl-confirm-btn" class="btn btn-blue" data-i18n="page-chat.create-confirm">Create Chat</button>
            </div>
        </div>
    </div>

    <div id="backlinks-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content lightbox-content-sm">
            <button id="backlinks-lightbox-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="backlinks.title">Backlinks</h3>
            <p id="backlinks-lightbox-subtitle" class="backlinks-subtitle"></p>
            <div id="backlinks-list" class="backlinks-list"></div>
        </div>
    </div>

    <div id="print-lightbox" class="print-lightbox hidden">
        <div class="print-lightbox-bar">
            <span id="print-lightbox-title" class="print-lightbox-title"></span>
            <div class="print-lightbox-actions">
                <button id="print-lightbox-print-btn" class="btn btn-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Print
                </button>
                <button id="print-lightbox-close-btn" class="lightbox-close" style="position:static;font-size:1.2rem;">&times;</button>
            </div>
        </div>
        <div id="print-lightbox-body" class="print-lightbox-body"></div>
    </div>

    <div id="link-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content">
            <button id="link-lightbox-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="link.title">Link to Page</h3>
            <div class="form-group" id="link-space-group">
                <label for="link-space-select" data-i18n="link.space-label">Space:</label>
                <select id="link-space-select" class="form-control"></select>
            </div>
            <div class="form-group" id="link-folder-group">
                <div id="link-file-tree" class="link-file-tree"></div>
            </div>
        </div>
    </div>

    <div id="external-link-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content lightbox-content-sm">
            <button id="external-link-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="ext-link.title">External Link</h3>
            <div class="form-group">
                <label for="external-link-url" data-i18n="ext-link.url-label">URL:</label>
                <input type="url" id="external-link-url" class="form-control" placeholder="https://example.com">
            </div>
            <div class="form-group">
                <label for="external-link-text" data-i18n="ext-link.text-label">Link Text:</label>
                <input type="text" id="external-link-text" class="form-control" data-i18n-placeholder="ext-link.text-ph" placeholder="Link text">
            </div>
            <div class="lightbox-footer">
                <button id="external-link-insert-btn" class="btn btn-green" data-i18n="ext-link.insert-btn">Insert Link</button>
            </div>
        </div>
    </div>

    <div id="copy-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content">
            <button id="copy-lightbox-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="copy.title">Copy Page</h3>
            <div class="form-group">
                <label for="copy-new-name" data-i18n="copy.name-label">New Page Name:</label>
                <input type="text" id="copy-new-name" class="form-control">
            </div>
            <div class="form-group" id="copy-space-group">
                <label for="copy-space-select" data-i18n="copy.space-label">Destination Space:</label>
                <select id="copy-space-select" class="form-control"></select>
            </div>
            <div class="form-group" id="copy-folder-group">
                <label data-i18n="copy.folder-label">Destination Folder:</label>
                <div id="copy-file-tree" class="link-file-tree"></div>
            </div>
            <div class="lightbox-footer">
                <button id="copy-confirm-btn" class="btn btn-green" data-i18n="copy.confirm-btn">Copy Page</button>
            </div>
        </div>
    </div>

    <div id="move-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content">
            <button id="move-lightbox-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="move.title">Move Item</h3>
            <div class="form-group" id="move-space-group">
                <label for="move-space-select" data-i18n="move.space-label">Destination Space:</label>
                <select id="move-space-select" class="form-control"></select>
            </div>
            <div class="form-group" id="move-folder-group">
                <label data-i18n="move.folder-label">Select Destination Folder:</label>
                <div id="move-file-tree" class="link-file-tree"></div>
            </div>
            <div class="lightbox-footer">
                <button id="move-confirm-btn" class="btn btn-blue" data-i18n="move.confirm-btn">Move Item</button>
            </div>
        </div>
    </div>

    <div id="save-msg-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content">
            <button id="save-msg-close-btn" class="lightbox-close">&times;</button>
            <h3 id="save-msg-title" data-i18n="chat.save.title-save">Save as Markdown Page</h3>
            <div class="form-group" id="save-msg-name-group">
                <label for="save-msg-name" data-i18n="chat.save.name-label">Filename:</label>
                <input type="text" id="save-msg-name" class="form-control">
            </div>
            <div class="form-group">
                <label for="save-msg-space-select" data-i18n="chat.save.space-label">Space:</label>
                <select id="save-msg-space-select" class="form-control"></select>
            </div>
            <div class="form-group">
                <label id="save-msg-tree-label" data-i18n="chat.save.folder-label">Folder:</label>
                <div id="save-msg-file-tree" class="link-file-tree"></div>
            </div>
            <div class="lightbox-footer">
                <button id="save-msg-confirm-btn" class="btn btn-green" data-i18n="chat.save.confirm">Save</button>
            </div>
        </div>
    </div>

    <div id="diagram-editor-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content full-screen">
            <button id="diagram-editor-close-btn" class="lightbox-close">&times;</button>
            <iframe id="diagram-editor-iframe" src="about:blank"></iframe>
        </div>
    </div>

    <div id="item-modal" class="lightbox-overlay hidden">
        <div class="lightbox-content">
            <button id="item-modal-close-btn" class="lightbox-close">&times;</button>
            <h3 id="item-modal-title">Add Item</h3>
            <form id="item-modal-form" class="modal-form"></form>
            <div class="lightbox-footer">
                <button id="item-modal-save-btn" class="btn btn-green">Save Item</button>
            </div>
        </div>
    </div>

    <div id="column-modal" class="lightbox-overlay hidden">
        <div class="lightbox-content">
            <button id="column-modal-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="col.title">Add New Column</h3>
            <form id="column-modal-form" class="modal-form">
                <div class="form-group">
                    <label for="column-name" data-i18n="col.name-label">Column Name:</label>
                    <input type="text" id="column-name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="column-type" data-i18n="col.type-label">Column Type:</label>
                    <select id="column-type" class="form-control">
                        <option value="text_single" data-i18n="col.type-single">Single line of text</option>
                        <option value="text_multi" data-i18n="col.type-multi">Multi-line text</option>
                        <option value="date" data-i18n="col.type-date">Date</option>
                        <option value="choice" data-i18n="col.type-choice">Choice (Dropdown)</option>
                    </select>
                </div>
                <div id="choice-options-group" class="form-group hidden">
                    <label for="choice-options" data-i18n="col.choices-label">Choices (comma-separated):</label>
                    <input type="text" id="choice-options" class="form-control">
                </div>
                <div class="form-group">
                    <label for="column-visible" data-i18n="col.visible-label">Show in list view:</label>
                    <select id="column-visible" class="form-control">
                        <option value="true" selected data-i18n="col.visible-yes">Yes</option>
                        <option value="false" data-i18n="col.visible-no">No</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="column-desc" data-i18n="col.desc-label">Description:</label>
                    <textarea id="column-desc" class="form-control" rows="2" data-i18n-placeholder="col.desc-ph" placeholder="Shown as help text when creating or editing items" style="resize:vertical"></textarea>
                </div>
            </form>
            <div class="lightbox-footer">
                <button id="column-modal-save-btn" class="btn btn-blue" data-i18n="col.add-btn">Add Column</button>
            </div>
        </div>
    </div>

    <div id="item-view-modal" class="lightbox-overlay hidden">
        <div class="lightbox-content">
            <button id="item-view-modal-close-btn" class="lightbox-close">&times;</button>
            <h3 id="item-view-modal-title" data-i18n="item-view.title">Item Details</h3>
            <div id="item-view-modal-content" class="modal-form"></div>
            <div class="lightbox-footer">
                <button id="item-view-modal-delete-btn" class="btn btn-danger" data-i18n="btn.delete">Delete</button>
                <button id="item-view-modal-edit-btn" class="btn btn-blue" data-i18n="item-view.edit-btn">Edit Item</button>
            </div>
        </div>
    </div>

    <div id="view-settings-modal" class="lightbox-overlay hidden">
        <div class="lightbox-content">
            <button id="view-settings-close-btn" class="lightbox-close">&times;</button>
            <h3 id="view-settings-title" data-i18n="view-settings.title">All Items</h3>
            <div id="view-settings-form" class="modal-form settings-column-list"></div>
            <div id="view-filters-section" class="hidden"></div>
            <div class="lightbox-footer">
                <button id="delete-view-btn" class="btn btn-danger btn-sm hidden" data-i18n="view-settings.del-view">Delete View</button>
                <button id="view-settings-save-btn" class="btn btn-green" data-i18n="view-settings.save-btn">Save</button>
            </div>
        </div>
    </div>

    <div id="list-props-modal" class="lightbox-overlay hidden">
        <div class="lightbox-content">
            <button id="list-props-modal-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="list-props.title">List Properties</h3>
            <div id="list-props-columns" class="modal-form list-props-columns"></div>
            <div class="lightbox-footer">
                <button id="list-props-save-btn" class="btn btn-green" data-i18n="list-props.save-btn">Save Changes</button>
            </div>
        </div>
    </div>

    <div id="hotkey-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content">
            <button id="hotkey-lightbox-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="hotkeys.title">Editor Hotkeys</h3>
            <div id="hotkey-list"></div>
            <p class="hotkey-help-text">
                <span data-i18n="hotkeys.hint1">When in edit mode, press 'Alt' + the desired key.</span>
                <br>
                <span data-i18n="hotkeys.hint2">While this menu is open, just press the key.</span>
            </p>
        </div>
    </div>

    <!-- Include Page lightbox -->
    <div id="include-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content">
            <button id="include-lightbox-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="include.title">Include Page</h3>
            <div id="include-file-tree" class="link-file-tree"></div>
        </div>
    </div>

    <!-- Insert Image lightbox -->
    <div id="insert-image-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content">
            <button id="insert-image-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="img.title">Insert Image</h3>
            <div id="image-attachment-list" class="image-attachment-list"></div>
            <div class="insert-image-upload">
                <button id="upload-image-btn" class="btn btn-sm btn-secondary" data-i18n="img.upload-btn">Upload New Image</button>
                <input type="file" id="image-upload-input" accept="image/*" class="hidden">
            </div>
            <div id="insert-image-selected" class="insert-image-selected hidden">
                <div class="insert-image-selected-name"><span data-i18n="img.selected">Selected: </span><strong id="selected-image-name"></strong></div>
                <div class="insert-image-dims">
                    <label><span data-i18n="img.width">Width: </span><input type="number" id="insert-image-width" placeholder="auto" min="1" class="form-control insert-dim-input"></label>
                    <label><span data-i18n="img.height">Height: </span><input type="number" id="insert-image-height" placeholder="auto" min="1" class="form-control insert-dim-input"></label>
                </div>
            </div>
            <div class="lightbox-footer">
                <button id="insert-image-confirm-btn" class="btn btn-blue" data-i18n="img.insert-btn" disabled>Insert Image</button>
            </div>
        </div>
    </div>

    <!-- Insert Diagram lightbox -->
    <div id="insert-diagram-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content">
            <button id="insert-diagram-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="insert-diagram.title">Insert Diagram</h3>
            <div id="insert-diagram-tree" class="link-file-tree"></div>
        </div>
    </div>

    <!-- Insert List lightbox -->
    <div id="insert-list-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content">
            <button id="insert-list-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="insert-list.title">Insert List</h3>
            <div id="insert-list-step-1">
                <p class="lightbox-hint" data-i18n="insert-list.hint1">Select a list to embed:</p>
                <div id="insert-list-tree" class="link-file-tree"></div>
            </div>
            <div id="insert-list-step-2" class="hidden">
                <p class="lightbox-hint" data-i18n="insert-list.hint2">Select view to display:</p>
                <div id="insert-list-views" class="insert-list-views"></div>
                <div class="lightbox-footer">
                    <button id="insert-list-back-btn" class="btn btn-secondary" data-i18n="insert-list.back-btn">&#8592; Back</button>
                    <button id="insert-list-confirm-btn" class="btn btn-blue" data-i18n="insert-list.insert-btn">Insert List</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Insert Comment lightbox -->
    <div id="comment-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content lightbox-comment">
            <button id="comment-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="comment.title">Insert Comment</h3>
            <div class="comment-compose-area">
                <div class="comment-input-wrap">
                    <textarea id="comment-input" class="comment-input-textarea" data-i18n-placeholder="comment.placeholder" placeholder="Write a comment… use # to mention someone" rows="4"></textarea>
                    <div id="comment-mention-popup" class="chat-mention-popup comment-mention-popup hidden"></div>
                </div>
                <div class="comment-compose-toolbar">
                    <div class="comment-emoji-wrap">
                        <button id="comment-emoji-btn" class="btn btn-sm btn-secondary" type="button">😊</button>
                        <div id="comment-emoji-picker" class="chat-emoji-picker comment-emoji-picker hidden"></div>
                    </div>
                    <span class="comment-compose-hint" data-i18n="comment.hint">Ctrl+Enter to insert</span>
                </div>
            </div>
            <div class="lightbox-footer">
                <button id="comment-cancel-btn" class="btn btn-secondary" data-i18n="comment.cancel-btn">Cancel</button>
                <button id="comment-confirm-btn" class="btn btn-blue" data-i18n="comment.insert-btn">Insert</button>
            </div>
        </div>
    </div>

    <!-- Confirm modal -->
    <div id="confirm-modal" class="lightbox-overlay hidden">
        <div class="lightbox-content input-modal-content">
            <div class="input-modal-header">
                <span id="confirm-modal-icon" class="input-modal-icon hidden"></span>
                <h3 id="confirm-modal-title"></h3>
            </div>
            <p id="confirm-modal-message" class="confirm-modal-message hidden"></p>
            <div class="lightbox-footer">
                <button id="confirm-modal-cancel" class="btn btn-secondary" data-i18n="btn.cancel">Cancel</button>
                <button id="confirm-modal-ok" class="btn btn-danger" data-i18n="btn.confirm">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Input prompt modal -->
    <div id="input-modal" class="lightbox-overlay hidden">
        <div class="lightbox-content input-modal-content">
            <div class="input-modal-header">
                <span id="input-modal-icon" class="input-modal-icon hidden"></span>
                <h3 id="input-modal-title"></h3>
            </div>
            <div class="form-group">
                <input type="text" id="input-modal-input" class="form-control" autocomplete="off">
            </div>
            <div class="lightbox-footer">
                <button id="input-modal-cancel" class="btn btn-secondary" data-i18n="btn.cancel">Cancel</button>
                <button id="input-modal-ok" class="btn btn-blue" data-i18n="btn.ok">OK</button>
            </div>
        </div>
    </div>

    <!-- Git version history lightbox -->
    <div id="git-history-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content">
            <button id="git-history-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="git.title">Version History</h3>
            <p id="git-history-filename" class="git-history-subtitle"></p>
            <div id="git-history-list" class="git-history-list"></div>
            <div id="git-diff-view" class="git-diff-view hidden">
                <div class="git-diff-header">
                    <button id="git-diff-back-btn" class="btn btn-sm btn-secondary" data-i18n="git.diff-back">← Back</button>
                    <span id="git-diff-meta" class="git-diff-meta-label"></span>
                </div>
                <pre id="git-diff-content" class="git-diff-content"></pre>
            </div>
        </div>
    </div>

    <!-- New Chat lightbox -->
    <div id="new-chat-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content lightbox-content-sm">
            <button id="new-chat-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="new.chat-dialog-title">New Chat</h3>
            <div class="form-group">
                <label class="form-label" data-i18n="new.chat-name-label">Name</label>
                <input id="new-chat-name" type="text" class="form-control" data-i18n-placeholder="new.untitled-chat" placeholder="Untitled Chat">
            </div>
            <div class="form-group">
                <label class="form-label" data-i18n="new.chat-topic-label">Topic (optional)</label>
                <textarea id="new-chat-topic" class="form-control" rows="2" data-i18n-placeholder="new.topic-ph" placeholder="What is this chat about?"></textarea>
            </div>
            <div class="lightbox-footer">
                <button id="new-chat-cancel-btn" class="btn btn-secondary" data-i18n="btn.cancel">Cancel</button>
                <button id="new-chat-create-btn" class="btn btn-green" data-i18n="btn.create">Create</button>
            </div>
        </div>
    </div>

    <!-- AI Agent Instructions lightbox -->
    <div id="agent-instructions-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content" style="max-width:680px;height:auto;max-height:80vh">
            <button id="agent-instructions-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="admin.ai.agent-instructions-title" style="margin:0 0 0.75rem">AI Agent Instructions</h3>
            <p style="font-size:0.8rem;color:var(--accent-gray);margin:0 0 0.75rem" data-i18n="admin.ai.agent-instructions-hint">Copy and paste into the system prompt or instructions field of your AI agent.</p>
            <textarea id="agent-instructions-text" class="agent-instructions-textarea" readonly></textarea>
            <div class="lightbox-footer">
                <button id="agent-instructions-copy-btn" class="btn btn-blue" data-i18n="admin.ai.agent-instructions-copy">Copy to Clipboard</button>
            </div>
        </div>
    </div>

    <!-- API Account help lightbox -->
    <div id="api-account-help-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content" style="max-width:640px;height:auto;max-height:82vh;overflow-y:auto">
            <button id="api-account-help-close-btn" class="lightbox-close">&times;</button>
            <h3 style="margin:0 0 0.9rem" data-i18n="admin.api.help-title">API Accounts — How to use</h3>
            <div style="font-size:0.88rem;line-height:1.6;color:var(--text-color)">
                <h4 style="margin:0 0 0.35rem;font-size:0.9rem" data-i18n="admin.api.help-h1">What is an API Account?</h4>
                <p style="margin:0 0 0.75rem" data-i18n="admin.api.help-p1">An API Account is a headless service account used to authenticate scripts, CI/CD pipelines, or any automation that needs to read or write wiki content via the HTTP API — without a human login.</p>
                <h4 style="margin:0 0 0.35rem;font-size:0.9rem" data-i18n="admin.api.help-h2">How to authenticate</h4>
                <p style="margin:0 0 0.4rem" data-i18n="admin.api.help-p2">Pass the service token in the <code>Authorization</code> header:</p>
                <pre style="background:var(--bg-alt);border:1px solid var(--border-color);border-radius:4px;padding:0.6rem 0.8rem;font-size:0.8rem;margin:0 0 0.75rem;overflow-x:auto">Authorization: Bearer wk_sys_&lt;token&gt;</pre>
                <h4 style="margin:0 0 0.35rem;font-size:0.9rem" data-i18n="admin.api.help-h3">API Accounts vs. AI Users</h4>
                <table style="width:100%;border-collapse:collapse;font-size:0.82rem;margin:0 0 0.75rem">
                    <thead><tr style="border-bottom:2px solid var(--border-color)">
                        <th style="padding:0.3rem 0.5rem;text-align:left"></th>
                        <th style="padding:0.3rem 0.5rem;text-align:left" data-i18n="admin.api.help-col-api">API Account</th>
                        <th style="padding:0.3rem 0.5rem;text-align:left" data-i18n="admin.api.help-col-ai">AI User</th>
                    </tr></thead>
                    <tbody>
                        <tr style="border-bottom:1px solid var(--border-color)"><td style="padding:0.3rem 0.5rem;color:var(--text-muted)" data-i18n="admin.api.help-row-token">Token prefix</td><td style="padding:0.3rem 0.5rem"><code>wk_sys_…</code></td><td style="padding:0.3rem 0.5rem"><code>wk_ai_…</code></td></tr>
                        <tr style="border-bottom:1px solid var(--border-color)"><td style="padding:0.3rem 0.5rem;color:var(--text-muted)" data-i18n="admin.api.help-row-ai">AI / LLM config</td><td style="padding:0.3rem 0.5rem" data-i18n="admin.api.help-no">No</td><td style="padding:0.3rem 0.5rem" data-i18n="admin.api.help-yes">Yes</td></tr>
                        <tr style="border-bottom:1px solid var(--border-color)"><td style="padding:0.3rem 0.5rem;color:var(--text-muted)" data-i18n="admin.api.help-row-chat">Chat / Agent Jobs</td><td style="padding:0.3rem 0.5rem" data-i18n="admin.api.help-no">No</td><td style="padding:0.3rem 0.5rem" data-i18n="admin.api.help-yes">Yes</td></tr>
                        <tr style="border-bottom:1px solid var(--border-color)"><td style="padding:0.3rem 0.5rem;color:var(--text-muted)" data-i18n="admin.api.help-row-read">Read/write pages</td><td style="padding:0.3rem 0.5rem" data-i18n="admin.api.help-yes">Yes</td><td style="padding:0.3rem 0.5rem" data-i18n="admin.api.help-yes">Yes</td></tr>
                        <tr><td style="padding:0.3rem 0.5rem;color:var(--text-muted)" data-i18n="admin.api.help-row-admin">Admin actions</td><td style="padding:0.3rem 0.5rem" data-i18n="admin.api.help-blocked">Always blocked</td><td style="padding:0.3rem 0.5rem" data-i18n="admin.api.help-blocked">Always blocked</td></tr>
                    </tbody>
                </table>
                <h4 style="margin:0 0 0.35rem;font-size:0.9rem" data-i18n="admin.api.help-h4">When to use which</h4>
                <ul style="margin:0 0 0 1.1rem;padding:0">
                    <li style="margin-bottom:0.3rem" data-i18n="admin.api.help-use-api">Use an <strong>API Account</strong> when a script or tool needs to read or write wiki pages — no AI features required.</li>
                    <li data-i18n="admin.api.help-use-ai">Use an <strong>AI User</strong> when you need the wiki to chat, respond in a conversation, or run scheduled Agent Jobs using a language model.</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- AI User help lightbox -->
    <div id="ai-user-help-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content" style="max-width:640px;height:auto;max-height:82vh;overflow-y:auto">
            <button id="ai-user-help-close-btn" class="lightbox-close">&times;</button>
            <h3 style="margin:0 0 0.9rem" data-i18n="admin.ai.help-title">AI Users — How to use</h3>
            <div style="font-size:0.88rem;line-height:1.6;color:var(--text-color)">
                <h4 style="margin:0 0 0.35rem;font-size:0.9rem" data-i18n="admin.ai.help-h1">What is an AI User?</h4>
                <p style="margin:0 0 0.75rem" data-i18n="admin.ai.help-p1">An AI User is an account backed by a language model (OpenAI, Anthropic, or any compatible API). It can participate in chat conversations, respond to messages, and run scheduled Agent Jobs — all using its configured model and system prompt.</p>
                <h4 style="margin:0 0 0.35rem;font-size:0.9rem" data-i18n="admin.ai.help-h2">How to authenticate</h4>
                <p style="margin:0 0 0.4rem" data-i18n="admin.ai.help-p2">Pass the service token in the <code>Authorization</code> header:</p>
                <pre style="background:var(--bg-alt);border:1px solid var(--border-color);border-radius:4px;padding:0.6rem 0.8rem;font-size:0.8rem;margin:0 0 0.75rem;overflow-x:auto">Authorization: Bearer wk_ai_&lt;token&gt;</pre>
                <h4 style="margin:0 0 0.35rem;font-size:0.9rem" data-i18n="admin.ai.help-h3">AI Users vs. API Accounts</h4>
                <table style="width:100%;border-collapse:collapse;font-size:0.82rem;margin:0 0 0.75rem">
                    <thead><tr style="border-bottom:2px solid var(--border-color)">
                        <th style="padding:0.3rem 0.5rem;text-align:left"></th>
                        <th style="padding:0.3rem 0.5rem;text-align:left" data-i18n="admin.api.help-col-ai">AI User</th>
                        <th style="padding:0.3rem 0.5rem;text-align:left" data-i18n="admin.api.help-col-api">API Account</th>
                    </tr></thead>
                    <tbody>
                        <tr style="border-bottom:1px solid var(--border-color)"><td style="padding:0.3rem 0.5rem;color:var(--text-muted)" data-i18n="admin.api.help-row-token">Token prefix</td><td style="padding:0.3rem 0.5rem"><code>wk_ai_…</code></td><td style="padding:0.3rem 0.5rem"><code>wk_sys_…</code></td></tr>
                        <tr style="border-bottom:1px solid var(--border-color)"><td style="padding:0.3rem 0.5rem;color:var(--text-muted)" data-i18n="admin.api.help-row-ai">AI / LLM config</td><td style="padding:0.3rem 0.5rem" data-i18n="admin.api.help-yes">Yes</td><td style="padding:0.3rem 0.5rem" data-i18n="admin.api.help-no">No</td></tr>
                        <tr style="border-bottom:1px solid var(--border-color)"><td style="padding:0.3rem 0.5rem;color:var(--text-muted)" data-i18n="admin.api.help-row-chat">Chat / Agent Jobs</td><td style="padding:0.3rem 0.5rem" data-i18n="admin.api.help-yes">Yes</td><td style="padding:0.3rem 0.5rem" data-i18n="admin.api.help-no">No</td></tr>
                        <tr style="border-bottom:1px solid var(--border-color)"><td style="padding:0.3rem 0.5rem;color:var(--text-muted)" data-i18n="admin.api.help-row-read">Read/write pages</td><td style="padding:0.3rem 0.5rem" data-i18n="admin.api.help-yes">Yes</td><td style="padding:0.3rem 0.5rem" data-i18n="admin.api.help-yes">Yes</td></tr>
                        <tr><td style="padding:0.3rem 0.5rem;color:var(--text-muted)" data-i18n="admin.api.help-row-admin">Admin actions</td><td style="padding:0.3rem 0.5rem" data-i18n="admin.api.help-blocked">Always blocked</td><td style="padding:0.3rem 0.5rem" data-i18n="admin.api.help-blocked">Always blocked</td></tr>
                    </tbody>
                </table>
                <h4 style="margin:0 0 0.35rem;font-size:0.9rem" data-i18n="admin.ai.help-h4">When to use an AI User</h4>
                <ul style="margin:0 0 0 1.1rem;padding:0">
                    <li style="margin-bottom:0.3rem" data-i18n="admin.ai.help-use-ai">Use an <strong>AI User</strong> when you want the wiki to respond in chat, run scheduled Agent Jobs, or call the API with AI capabilities.</li>
                    <li data-i18n="admin.ai.help-use-api">Use an <strong>API Account</strong> instead when you only need read/write access from a script — no language model required.</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Clone AI User lightbox -->
    <div id="clone-ai-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content" style="max-width:520px;height:auto;max-height:82vh;overflow-y:auto">
            <button id="clone-ai-close-btn" class="lightbox-close">&times;</button>
            <h3 style="margin:0 0 0.9rem">Clone AI User</h3>
            <div style="font-size:0.85rem;line-height:1.6;color:var(--text-muted);background:var(--bg-alt);border:1px solid var(--border-color);border-radius:6px;padding:0.75rem 0.9rem;margin-bottom:1rem">
                <p style="margin:0 0 0.5rem"><strong style="color:var(--text-color)">Why clone an AI User?</strong></p>
                <p style="margin:0 0 0.5rem">Cloning lets you create multiple AI Users backed by the same model, each with a different <em>System Prompt</em> that defines a specific role or area of expertise. That way, the AI already knows its job the moment you #mention it — no need to re-explain in every message.</p>
                <p style="margin:0 0 0.4rem">Examples:</p>
                <ul style="margin:0 0 0 1.1rem;padding:0">
                    <li style="margin-bottom:0.25rem"><strong>#botPO</strong> — <em>"Think like a Product Owner. Focus on user value, acceptance criteria and backlog priorities. Stay within the product domain."</em></li>
                    <li style="margin-bottom:0.25rem"><strong>#botDev</strong> — <em>"You are a senior backend developer. Prefer code over prose. Be concise."</em></li>
                    <li><strong>#botQA</strong> — <em>"You are a QA engineer. Think in test cases, edge cases and failure modes."</em></li>
                </ul>
            </div>
            <div class="form-group" style="margin-bottom:1.1rem">
                <label style="font-size:0.85rem;font-weight:600;display:block;margin-bottom:0.35rem">Name for the new AI User</label>
                <input type="text" id="clone-ai-name" class="form-control" placeholder="e.g. botPO" autocomplete="off">
            </div>
            <div style="display:flex;justify-content:flex-end;gap:0.5rem">
                <button id="clone-ai-cancel-btn" class="btn btn-secondary">Cancel</button>
                <button id="clone-ai-confirm-btn" class="btn btn-green">Clone</button>
            </div>
        </div>
    </div>

    <!-- New Page lightbox -->
    <div id="new-page-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content lightbox-content-sm">
            <button id="new-page-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="new.page-dialog-title">New Page</h3>
            <div class="form-group">
                <label class="form-label" data-i18n="new.page-name-label">Name</label>
                <input id="new-page-name" type="text" class="form-control" data-i18n-placeholder="new.untitled-page" placeholder="Untitled Page">
            </div>
            <div id="new-page-template-group" class="form-group hidden">
                <label class="form-label" data-i18n="new.page-template-label">Template</label>
                <div id="new-page-template-list" class="new-page-template-list"></div>
            </div>
            <div class="lightbox-footer">
                <button id="new-page-cancel-btn" class="btn btn-secondary" data-i18n="btn.cancel">Cancel</button>
                <button id="new-page-create-btn" class="btn btn-green" data-i18n="btn.create">Create</button>
            </div>
        </div>
    </div>

    <?php if ($mailConfigured === '1'): ?>
    <div id="share-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content lightbox-content-sm">
            <button id="share-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="share.dialog-title">Share Page</h3>
            <div class="share-form-grid">
                <label class="share-grid-label" data-i18n="share.subject-label">Subject</label>
                <input id="share-subject" type="text" class="form-control">

                <label class="share-grid-label" data-i18n="share.to-label">To</label>
                <div class="share-to-col">
                    <div class="share-to-options">
                        <label class="share-to-option">
                            <input type="radio" name="share-to" value="everyone" checked>
                            <span data-i18n="share.to-everyone">Everyone</span>
                        </label>
                        <label class="share-to-option">
                            <input type="radio" name="share-to" value="specific">
                            <span data-i18n="share.to-specific">Specific recipients</span>
                        </label>
                    </div>
                    <div id="share-recipient-wrap" class="share-recipient-wrap hidden">
                        <div id="share-chips-input" class="share-chips-input">
                            <input id="share-typeahead" type="text" class="share-typeahead" placeholder="Type a name…" autocomplete="off">
                        </div>
                        <ul id="share-suggestions" class="share-suggestions hidden"></ul>
                    </div>
                </div>

                <div class="share-message-section">
                    <label class="form-label" data-i18n="share.message-label">Message</label>
                    <textarea id="share-message" class="form-control share-message"></textarea>
                </div>
            </div>
            <div class="lightbox-footer">
                <button id="share-cancel-btn" class="btn btn-secondary" data-i18n="btn.cancel">Cancel</button>
                <button id="share-send-btn" class="btn btn-green" data-i18n="share.send-btn">Send e-mail</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div id="new-diagram-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content lightbox-content-sm">
            <button id="new-diagram-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="new.diagram-dialog-title">New Diagram</h3>
            <div class="form-group">
                <label class="form-label" data-i18n="new.diagram-name-label">Name</label>
                <input id="new-diagram-name" type="text" class="form-control" data-i18n-placeholder="new.untitled-diagram" placeholder="Untitled Diagram">
            </div>
            <div id="new-diagram-template-group" class="form-group hidden">
                <label class="form-label" data-i18n="new.diagram-template-label">Template</label>
                <div id="new-diagram-template-list" class="new-page-template-list"></div>
            </div>
            <div class="lightbox-footer">
                <button id="new-diagram-cancel-btn" class="btn btn-secondary" data-i18n="btn.cancel">Cancel</button>
                <button id="new-diagram-create-btn" class="btn btn-green" data-i18n="btn.create">Create</button>
            </div>
        </div>
    </div>

    <!-- Chat topic edit lightbox -->
    <div id="chat-topic-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content lightbox-content-sm">
            <button id="chat-topic-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="chat-topic.title">Edit Topic</h3>
            <div class="form-group chat-topic-input-group">
                <textarea id="chat-topic-input" class="form-control" rows="3" data-i18n-placeholder="chat-topic.ph" placeholder="What is this chat about?"></textarea>
                <div id="chat-topic-emoji-picker" class="chat-emoji-picker hidden"></div>
            </div>
            <div class="lightbox-footer">
                <button id="chat-topic-emoji-btn" class="btn btn-icon btn-secondary chat-emoji-btn" title="Emoji" style="margin-right:auto">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
                </button>
                <button id="chat-topic-cancel-btn" class="btn btn-secondary" data-i18n="btn.cancel">Cancel</button>
                <button id="chat-topic-save-btn" class="btn btn-green" data-i18n="btn.save">Save</button>
            </div>
        </div>
    </div>

    <div id="ai-processing-modal" class="lightbox-overlay hidden" style="z-index:1100">
        <div class="lightbox-content lightbox-content-sm ai-processing-content">
            <div class="ai-processing-spinner"></div>
            <p id="ai-processing-label" class="ai-processing-label"><span class="ai-processing-icon">🤖</span><span id="ai-processing-name">AI</span> is thinking…</p>
            <div id="ai-status-panel" class="ai-status-panel hidden">
                <div id="ai-status-step" class="ai-status-step"></div>
                <div id="ai-status-meta" class="ai-status-meta"></div>
                <div id="ai-status-timer" class="ai-status-timer">0s</div>
            </div>
            <button id="ai-processing-cancel-btn" class="btn btn-secondary" data-i18n="btn.cancel">Cancel</button>
        </div>
    </div>

    <div id="toast"><span id="toast-message"></span></div>

    <?php if (AUTHENTICATION_ENABLED && isset($_SESSION['user'])): ?>
    <div id="preferences-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content input-modal-content" style="max-width:440px">
            <button id="preferences-lightbox-close-btn" class="lightbox-close">&times;</button>
            <h3 data-i18n="prefs.title">My Preferences</h3>
            <div class="form-group">
                <label data-i18n="prefs.name-label">Name</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($_SESSION['user']['name'] ?? ''); ?>" readonly>
            </div>
            <div class="form-group">
                <label for="pref-email" data-i18n="prefs.email-label">Email</label>
                <input type="email" id="pref-email" class="form-control" data-i18n-placeholder="prefs.email-ph" placeholder="your@email.com">
            </div>
            <div class="form-group">
                <label data-i18n="prefs.font-label">Font</label>
                <div style="display:flex;gap:0.5rem;margin-top:0.25rem">
                    <button class="btn btn-sm" data-font-val="sans" data-i18n="prefs.font-sans">Sans-serif</button>
                    <button class="btn btn-sm" data-font-val="serif" data-i18n="prefs.font-serif">Serif</button>
                    <button class="btn btn-sm" data-font-val="mono" data-i18n="prefs.font-mono">Mono</button>
                </div>
            </div>
            <div class="form-group">
                <label data-i18n="prefs.size-label">Text Size</label>
                <div style="display:flex;gap:0.5rem;margin-top:0.25rem">
                    <button class="btn btn-sm pref-size-btn" data-size-val="10pt" style="font-size:10pt">10pt</button>
                    <button class="btn btn-sm pref-size-btn" data-size-val="11pt" style="font-size:11pt">11pt</button>
                    <button class="btn btn-sm pref-size-btn" data-size-val="12pt" style="font-size:12pt">12pt</button>
                    <button class="btn btn-sm pref-size-btn" data-size-val="14pt" style="font-size:14pt">14pt</button>
                    <button class="btn btn-sm pref-size-btn" data-size-val="16pt" style="font-size:16pt">16pt</button>
                </div>
            </div>
            <div class="lightbox-footer">
                <button id="preferences-save-btn" class="btn btn-blue" data-i18n="prefs.save-btn">Save</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($userRole === 'admin'): ?>
    <div id="admin-lightbox" class="lightbox-overlay hidden">
        <div class="lightbox-content admin-lightbox-content">
            <button id="admin-lightbox-close-btn" class="lightbox-close">&times;</button>

            <div class="admin-header">
                <h3 data-i18n="admin.title">Admin</h3>
                <div class="admin-group-bar">
                    <button class="admin-group active" data-group="users">Users</button>
                    <button class="admin-group" data-group="ai">AI</button>
                    <button class="admin-group" data-group="monitoring">Monitoring</button>
                    <button class="admin-group" data-group="content">Content</button>
                </div>
            </div>
            <div class="admin-tab-bar">
                <button class="admin-tab active" data-tab="users" data-group="users" data-i18n="admin.tab.users">Users</button>
                <button class="admin-tab" data-tab="requests" data-group="users"><span data-i18n="admin.tab.requests">Requests</span> <span id="admin-requests-badge" class="admin-badge hidden"></span></button>
                <button class="admin-tab" data-tab="api" data-group="users" data-i18n="admin.tab.api">API Accounts</button>
                <button class="admin-tab hidden" data-tab="ai" data-group="ai" data-i18n="admin.tab.ai">AI Users</button>
                <button class="admin-tab hidden" data-tab="jobs" data-group="ai" data-i18n="admin.tab.jobs">Agent Jobs</button>
                <button class="admin-tab hidden" data-tab="mcp" data-group="ai" data-i18n="admin.tab.mcp">MCP Servers</button>
                <button class="admin-tab hidden" data-tab="logs" data-group="monitoring" data-i18n="admin.tab.logs">Access Log</button>
                <button class="admin-tab hidden" data-tab="errorlog" data-group="monitoring" data-i18n="admin.tab.errorlog">Error Log</button>
                <button class="admin-tab hidden" data-tab="diagnostics" data-group="monitoring" data-i18n="admin.tab.diag">Diagnostics</button>
                <button class="admin-tab hidden" data-tab="reindex" data-group="content" data-i18n="admin.tab.reindex">Index Pages</button>
                <button class="admin-tab hidden" data-tab="deleted" data-group="content" data-i18n="admin.tab.deleted">Deleted Pages</button>
            </div>

            <!-- Users pane -->
            <div id="admin-pane-users" class="admin-pane">
                <div id="admin-users-table" class="admin-scroll-area"></div>
                <p class="admin-role-hint" data-i18n="admin.role-hint" style="margin-top:0.75rem">
                    <?php if (in_array(AUTHENTICATION, ['otp', 'both'])): ?>
                    Admin — full access · Editor — read and write · Reader — read only. Add OTP users with the button below.
                    <?php else: ?>
                    Admin — full access including user management · Editor — read and write · Reader — read only. New users are added via the Requests tab after they sign in for the first time.
                    <?php endif; ?>
                </p>
            </div>

            <!-- Requests pane -->
            <div id="admin-pane-requests" class="admin-pane hidden">
                <div id="admin-requests-table" class="admin-scroll-area"></div>
            </div>

            <!-- Logs pane -->
            <div id="admin-pane-logs" class="admin-pane hidden">
                <div class="admin-log-toolbar">
                    <select id="admin-log-date" class="form-control admin-log-date-select"></select>
                    <button id="admin-log-refresh-btn" class="btn btn-sm btn-secondary">&#8635; Refresh</button>
                </div>
                <div id="admin-log-entries" class="admin-scroll-area"></div>
            </div>

            <!-- Error Log pane -->
            <div id="admin-pane-errorlog" class="admin-pane hidden">
                <div class="admin-log-toolbar">
                    <select id="admin-diag-error-log-select" class="form-control admin-log-date-select"></select>
                    <button id="admin-diag-error-log-refresh-btn" class="btn btn-sm btn-secondary">&#8635; Refresh</button>
                </div>
                <div id="admin-diag-error-log-output" class="admin-scroll-area"></div>
            </div>

            <!-- AI Users pane -->
            <div id="admin-pane-ai" class="admin-pane hidden">
                <div id="admin-ai-list" class="admin-scroll-area"></div>
            </div>

            <!-- API Accounts pane -->
            <div id="admin-pane-api" class="admin-pane hidden">
                <div id="admin-api-list" class="admin-scroll-area"></div>
            </div>

            <!-- Agent Jobs pane -->
            <div id="admin-pane-jobs" class="admin-pane hidden">
                <div id="admin-jobs-list" class="admin-scroll-area"></div>
            </div>

            <!-- MCP Servers pane -->
            <div id="admin-pane-mcp" class="admin-pane hidden">
                <div id="admin-mcp-list" class="admin-scroll-area"></div>
            </div>

            <!-- Deleted Pages pane -->
            <div id="admin-pane-deleted" class="admin-pane hidden">
                <div id="admin-deleted-list" class="admin-scroll-area"></div>
            </div>

            <!-- Index Pages pane -->
            <div id="admin-pane-reindex" class="admin-pane hidden">
                <div class="admin-scroll-area">
                    <div class="admin-reindex-body">
                        <p class="admin-reindex-desc" data-i18n="admin.reindex.desc"></p>
                        <div class="admin-reindex-controls">
                            <label class="admin-reindex-label" data-i18n="admin.reindex.space-label"></label>
                            <select id="admin-reindex-space" class="form-control admin-reindex-select"></select>
                            <button id="admin-reindex-btn" class="btn btn-blue" data-i18n="admin.reindex.btn">Rebuild Index</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Diagnostics pane -->
            <div id="admin-pane-diagnostics" class="admin-pane hidden">
            <div class="admin-scroll-area admin-diag-scroll">
                <div class="admin-diag-section">
                    <div class="admin-diag-header">
                        <strong>Test Email</strong>
                        <button id="admin-diag-email-btn" class="btn btn-sm btn-secondary">Send test email to my address</button>
                    </div>
                    <div id="admin-diag-email-status" class="admin-diag-status"></div>
                </div>
                <div class="admin-diag-section">
                    <div class="admin-diag-header">
                        <strong>PHP Error Log</strong>
                        <button class="btn btn-sm btn-secondary admin-diag-refresh-btn" data-log-type="php" data-output="admin-diag-php-output">&#8635; Refresh</button>
                    </div>
                    <div id="admin-diag-php-output" class="admin-diag-output"></div>
                </div>
                <div class="admin-diag-section">
                    <div class="admin-diag-header">
                        <strong>NGINX Error Log</strong>
                        <button class="btn btn-sm btn-secondary admin-diag-refresh-btn" data-log-type="nginx_error" data-output="admin-diag-nginx-error-output">&#8635; Refresh</button>
                    </div>
                    <div id="admin-diag-nginx-error-output" class="admin-diag-output"></div>
                </div>
                <div class="admin-diag-section">
                    <div class="admin-diag-header">
                        <strong>NGINX Access Log</strong>
                        <button class="btn btn-sm btn-secondary admin-diag-refresh-btn" data-log-type="nginx_access" data-output="admin-diag-nginx-access-output">&#8635; Refresh</button>
                    </div>
                    <div id="admin-diag-nginx-access-output" class="admin-diag-output"></div>
                </div>
            </div><!-- end admin-diag-scroll -->
            </div>

            <!-- Footer (context-sensitive per tab) -->
            <div class="lightbox-footer">
                <div id="admin-footer-users" class="admin-footer-pane">
                    <span id="admin-dirty-notice" class="admin-dirty-notice hidden" data-i18n="admin.unsaved">Unsaved changes</span>
                    <button id="admin-otp-add-btn" class="btn btn-blue btn-sm hidden">+ Add OTP User</button>
                    <button id="admin-save-btn" class="btn btn-green" data-i18n="admin.save-btn" disabled>Save Changes</button>
                </div>
                <div id="admin-footer-requests" class="admin-footer-pane hidden">
                    <span id="admin-requests-count" class="admin-log-count"></span>
                </div>
                <div id="admin-footer-logs" class="admin-footer-pane hidden">
                    <span id="admin-log-count" class="admin-log-count"></span>
                </div>
                <div id="admin-footer-errorlog" class="admin-footer-pane hidden"></div>
                <div id="admin-footer-diagnostics" class="admin-footer-pane hidden"></div>
                <div id="admin-footer-ai" class="admin-footer-pane hidden">
                    <button id="admin-ai-add-btn" class="btn btn-blue btn-sm" data-i18n="admin.new-ai-btn">+ New AI User</button>
                </div>
                <div id="admin-footer-api" class="admin-footer-pane hidden">
                    <button id="admin-api-add-btn" class="btn btn-blue btn-sm" data-i18n="admin.new-api-btn">+ New API Account</button>
                </div>
                <div id="admin-footer-jobs" class="admin-footer-pane hidden">
                    <button id="admin-jobs-add-btn" class="btn btn-blue btn-sm" data-i18n="admin.jobs.add-btn">+ New Agent Job</button>
                </div>
                <div id="admin-footer-mcp" class="admin-footer-pane hidden">
                    <button id="admin-mcp-add-btn" class="btn btn-blue btn-sm">+ New MCP Server</button>
                </div>
                <div id="admin-footer-deleted" class="admin-footer-pane hidden">
                    <span id="admin-deleted-count" class="admin-log-count"></span>
                </div>
                <div id="admin-footer-reindex" class="admin-footer-pane hidden"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script>
        window.WIKI_ROLE       = '<?php echo htmlspecialchars($userRole); ?>';
        window.WIKI_USER_EMAIL = '<?php echo htmlspecialchars($_SESSION['user']['email'] ?? ''); ?>';
        window.WIKI_USER_SUB   = '<?php echo htmlspecialchars($_SESSION['user']['sub']   ?? ''); ?>';
        window.WIKI_USER_UID   = <?php echo (int)($_SESSION['user']['uid'] ?? 0); ?>;
        window.WIKI_USER_NAME  = '<?php echo htmlspecialchars($_SESSION['user']['name']  ?? ''); ?>';
        window.WIKI_USER_FONT      = '<?php echo htmlspecialchars($userFont); ?>';
        window.WIKI_USER_FONT_SIZE = '<?php echo htmlspecialchars($userFontSize); ?>';
        window.WIKI_USER_SPACES  = <?php echo json_encode($_SESSION['user']['spaces'] ?? null); ?>; // null = all spaces
        window.WIKI_SESSION_TIMEOUT = <?php echo (AUTHENTICATION_ENABLED && defined('SESSION_TIMEOUT')) ? (int)SESSION_TIMEOUT : 0; ?>;
        window.WIKI_AUTH_MODE = '<?php echo AUTHENTICATION; ?>';
        window.WIKI_SEARCH_ENGINE = '<?php echo defined('SEARCH_ENGINE') ? SEARCH_ENGINE : 'basic'; ?>';
    </script>
    <script src="script.js?v=<?php echo filemtime(__DIR__ . '/script.js'); ?>" type="module"></script>

    <?php if (AUTHENTICATION_ENABLED && isset($_SESSION['user'])): ?>
    <div id="session-warning" class="session-warning hidden">
        <span id="session-warning-text"></span>
        <button id="session-stay-btn" class="btn btn-sm btn-blue" data-i18n="session.stay">Stay logged in</button>
    </div>
    <?php endif; ?>
</body>
</html>