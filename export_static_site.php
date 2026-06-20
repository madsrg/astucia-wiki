#!/usr/bin/env php
<?php
// =================================================================
// AstuciaWiki — Static Site Exporter
// Usage: php export_static_site.php <destination_folder>
// =================================================================

if (PHP_SAPI !== 'cli') { die("Run from CLI only.\n"); }
if ($argc < 2) { echo "Usage: php export_static_site.php <destination_folder>\n"; exit(1); }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/indexer.php';
require_once __DIR__ . '/vendor/autoload.php';

$destDir  = rtrim($argv[1], '/');
$pagesDir = rtrim(PAGES_DIR, '/');

// ── Setup ─────────────────────────────────────────────────────────────────────

foreach ([$destDir, "$destDir/assets", "$destDir/files", "$destDir/pages"] as $d) {
    if (!is_dir($d)) mkdir($d, 0755, true);
}

$parsedown = new Parsedown();
$parsedown->setSafeMode(false);

$indexer = new PageIndexer($pagesDir);
$allPages = $indexer->getAllPages(); // id => [path, tags]

$idToPath = [];
foreach ($allPages as $id => $data) {
    if (isset($data['path'])) $idToPath[(string)$id] = $data['path'];
}

// ── File tree helpers ─────────────────────────────────────────────────────────

function buildTree(string $dir, string $pagesDir, PageIndexer $indexer): array {
    $items = [];
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (str_ends_with($entry, '.uploads') || str_ends_with($entry, '.svg')) continue;
        $full = "$dir/$entry";
        $rel  = ltrim(str_replace($pagesDir . '/', '', $full), '/');
        if (is_dir($full)) {
            $children = buildTree($full, $pagesDir, $indexer);
            if ($children) $items[] = ['type' => 'folder', 'name' => $entry, 'children' => $children];
        } else {
            $ext = pathinfo($entry, PATHINFO_EXTENSION);
            if (!in_array($ext, ['md', 'drawio', 'list'])) continue;
            $id = $indexer->getId($rel);
            if ($id === null) continue;
            $items[] = ['type' => 'file', 'name' => $entry, 'path' => $rel, 'id' => $id, 'ext' => $ext];
        }
    }
    usort($items, fn($a, $b) =>
        $a['type'] === $b['type'] ? strcmp($a['name'], $b['name']) : ($a['type'] === 'folder' ? -1 : 1)
    );
    return $items;
}

function renderNavTree(array $items, int $activeId, string $pagePrefix = ''): string {
    $html = '<ul class="static-nav-list">';
    foreach ($items as $item) {
        if ($item['type'] === 'folder') {
            $html .= '<li>';
            $html .= '<span class="static-nav-folder" onclick="toggleFolder(this)">▶ ' . htmlspecialchars($item['name']) . '</span>';
            $html .= '<div class="static-nav-children" style="display:none">';
            $html .= renderNavTree($item['children'], $activeId, $pagePrefix);
            $html .= '</div></li>';
        } else {
            $label = preg_replace('/\.(md|drawio|list)$/', '', $item['name']);
            $active = $item['id'] == $activeId ? ' class="static-nav-active"' : '';
            $html .= '<li><a href="' . $pagePrefix . 'page-' . $item['id'] . '.html"' . $active . '>' . htmlspecialchars($label) . '</a></li>';
        }
    }
    return $html . '</ul>';
}

// ── Content processors (PHP equivalents of the JS pipeline) ──────────────────

function resolvePagePath(string $id, array $idToPath): ?string {
    return $idToPath[$id] ?? null;
}

function processIncludes(string $content, array $idToPath, string $pagesDir, array $seen = []): string {
    return preg_replace_callback('/{include:(\d+)}/', function ($m) use ($idToPath, $pagesDir, $seen) {
        $id = $m[1];
        if (in_array($id, $seen)) return "[Error: Circular include for ID $id]";
        $path = resolvePagePath($id, $idToPath);
        if (!$path) return "[Error: Page ID $id not found]";
        $full = "$pagesDir/$path";
        if (!file_exists($full)) return "[Error: File not found for ID $id]";
        $sub = file_get_contents($full);
        $sub = processIncludes($sub, $idToPath, $pagesDir, [...$seen, $id]);
        $filename = preg_replace('/\.(md|drawio|list)$/', '', basename($path));
        $sub = str_replace('{filename}', $filename, $sub);
        $mtime = filemtime($full);
        $sub = str_replace('{lastUpdated}', date('Y-m-d H:i', $mtime), $sub);
        return $sub;
    }, $content);
}

function applyListFilters(array $items, array $view): array {
    if (empty($view['filters'])) return $items;
    return array_values(array_filter($items, function ($item) use ($view) {
        foreach ($view['filters'] as $f) {
            $val = strtolower((string)($item[$f['colId']] ?? ''));
            if (!str_contains($val, strtolower($f['value']))) return false;
        }
        return true;
    }));
}

function renderListAsHtml(array $listData, ?string $arg): string {
    $columns = $listData['columns'] ?? [];
    $items   = $listData['items']   ?? [];
    $views   = $listData['views']   ?? [];

    // Resolve columns and apply filters (mirrors JS processListTags logic)
    if ($arg !== null && !str_contains($arg, ',')) {
        $view = null;
        foreach ($views as $v) {
            if (strcasecmp($v['name'], $arg) === 0) { $view = $v; break; }
        }
        if ($view) {
            $colIds  = $view['columns'] ?? [];
            $columns = array_values(array_filter(array_map(fn($cid) =>
                current(array_filter($columns, fn($c) => $c['id'] === $cid)) ?: null,
                $colIds
            )));
            $items = applyListFilters($items, $view);
        } else {
            // Treat as column name
            $ordered = [];
            foreach (explode(',', $arg) as $cname) {
                foreach ($columns as $c) {
                    if (strcasecmp($c['name'], trim($cname)) === 0) { $ordered[] = $c; break; }
                }
            }
            $columns = $ordered;
        }
    } elseif ($arg !== null) {
        $ordered = [];
        foreach (explode(',', $arg) as $cname) {
            foreach ($columns as $c) {
                if (strcasecmp($c['name'], trim($cname)) === 0) { $ordered[] = $c; break; }
            }
        }
        $columns = $ordered;
    } else {
        $columns = array_values(array_filter($columns, fn($c) => ($c['showInListView'] ?? true) !== false));
    }

    $e = fn($s) => htmlspecialchars((string)($s ?? ''));

    $html = '<div class="inline-list-view"><table class="list-table"><thead><tr>';
    foreach ($columns as $col) $html .= '<th>' . $e($col['name']) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($items as $item) {
        $html .= '<tr>';
        foreach ($columns as $col) $html .= '<td>' . $e($item[$col['id']] ?? '') . '</td>';
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
}

function processListTags(string $content, array $idToPath, string $pagesDir): string {
    return preg_replace_callback('/{list:(\d+)(?::([^}]*))?}/', function ($m) use ($idToPath, $pagesDir) {
        $id  = $m[1];
        $arg = isset($m[2]) ? trim($m[2]) : null;
        if ($arg === '') $arg = null;
        $path = resolvePagePath($id, $idToPath);
        if (!$path) return "[List $id not found]";
        $full = "$pagesDir/$path";
        if (!file_exists($full)) return "[List file not found: $id]";
        $listData = json_decode(file_get_contents($full), true);
        if (!$listData) return "[Error parsing list $id]";
        return renderListAsHtml($listData, $arg);
    }, $content);
}

function getDiagramSvg(string $filePath): ?string {
    $svgPath = $filePath . '.svg';
    if (!file_exists($svgPath) || filemtime($svgPath) < filemtime($filePath)) {
        $cmd = 'DISPLAY=:0 /usr/bin/drawio --export --format svg --border 8 --output '
             . escapeshellarg($svgPath) . ' ' . escapeshellarg($filePath) . ' 2>&1';
        exec($cmd, $out, $code);
        if ($code !== 0 || !file_exists($svgPath)) return null;
    }
    return file_get_contents($svgPath);
}

function processDiagramTags(string $content, array $idToPath, string $pagesDir): string {
    return preg_replace_callback('/{diagram:(\d+)}/', function ($m) use ($idToPath, $pagesDir) {
        $id   = $m[1];
        $path = resolvePagePath($id, $idToPath);
        if (!$path) return "[Diagram $id not found]";
        $svg = getDiagramSvg("$pagesDir/$path");
        if (!$svg) return "[Error exporting diagram $id]";
        return '<div class="inline-diagram-viewer"><img src="data:image/svg+xml;base64,'
             . base64_encode($svg) . '" style="max-width:100%;height:auto;" alt="' . htmlspecialchars(basename($path)) . '"></div>';
    }, $content);
}

function rewriteLinks(string $html, array $idToPath, string $fileBase = '../files/'): string {
    // index.php?pageid=X  →  page-X.html (same pages/ folder)
    $html = preg_replace('/href="index\.php\?pageid=(\d+)"/', 'href="page-$1.html"', $html);
    // getfile.php?path=encoded  →  {fileBase}decoded
    $html = preg_replace_callback('/(?:src|href)="getfile\.php\?path=([^"]+)"/', function ($m) use ($fileBase) {
        $decoded = rawurldecode($m[1]);
        $decoded = ltrim(str_replace('..', '', $decoded), '/');
        $attr = str_starts_with($m[0], 'src') ? 'src' : 'href';
        return $attr . '="' . $fileBase . htmlspecialchars($decoded) . '"';
    }, $html);
    return $html;
}

// ── HTML page template ────────────────────────────────────────────────────────

function renderHtmlPage(string $pageTitle, string $content, string $navHtml, string $tagsHtml, string $assetBase = '../assets/'): string {
    $appTitle = APP_TITLE;
    $escaped  = htmlspecialchars($pageTitle);
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>{$escaped} — {$appTitle}</title>
<link rel="stylesheet" href="{$assetBase}styles.css">
<style>
  html,body{height:auto;overflow:auto}
  .app-container{min-height:100vh}
  .sidebar{position:sticky;top:0;height:100vh;overflow-y:auto;flex-shrink:0}
  .main-content{flex:1;min-width:0;overflow-y:auto;padding:2rem}
  .viewer-content img{max-width:100%;height:auto}
  /* hide interactive chrome */
  .sidebar-toggle-btn,.sidebar-actions,.sidebar-footer,.pane-tabs{display:none}
  .pane-content{display:block!important}
  /* static nav */
  .static-nav-list{list-style:none;margin:0;padding:0}
  .static-nav-list .static-nav-list{padding-left:1rem}
  .static-nav-list li{margin:2px 0}
  .static-nav-list a{display:block;padding:3px 6px;color:var(--sidebar-text);text-decoration:none;border-radius:3px;font-size:0.9em}
  .static-nav-list a:hover{background:rgba(255,255,255,0.1)}
  .static-nav-active{background:var(--accent-blue)!important;color:#fff!important}
  .static-nav-folder{display:block;padding:3px 6px;color:var(--sidebar-text);cursor:pointer;font-size:0.9em;border-radius:3px;user-select:none}
  .static-nav-folder:hover{background:rgba(255,255,255,0.08)}
  .page-header{margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:1px solid var(--border-color)}
  .page-header h2{margin:0 0 0.4rem;font-size:1.2rem}
  .static-tags{display:flex;flex-wrap:wrap;gap:4px}
  .static-tag{background:var(--accent-blue);color:#fff;padding:2px 8px;border-radius:10px;font-size:0.75em}
</style>
</head>
<body>
<div class="app-container">
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="logo-wrapper"><img src="{$assetBase}logo.png" class="sidebar-logo" alt="Logo"></div>
      <h1 style="font-size:1.1rem;text-align:center">{$appTitle}</h1>
    </div>
    <div class="sidebar-panes">
      <div class="pane-content active">{$navHtml}</div>
    </div>
  </aside>
  <main class="main-content">
    <div class="page-header">
      <h2>{$escaped}</h2>
      {$tagsHtml}
    </div>
    <div class="viewer-content">{$content}</div>
  </main>
</div>
<script>
function toggleFolder(el){
  var c=el.nextElementSibling;
  var open=c.style.display!=='none';
  c.style.display=open?'none':'';
  el.textContent=(open?'▶ ':'▼ ')+el.textContent.slice(2);
}
// Auto-expand path to active page
(function(){
  var a=document.querySelector('.static-nav-active');
  if(!a)return;
  var node=a.closest('.static-nav-children');
  while(node){
    node.style.display='';
    var folder=node.previousElementSibling;
    if(folder&&folder.classList.contains('static-nav-folder'))
      folder.textContent='▼ '+folder.textContent.slice(2);
    node=node.parentElement&&node.parentElement.closest('.static-nav-children');
  }
})();
</script>
</body>
</html>
HTML;
}

// ── Copy attachments ──────────────────────────────────────────────────────────

function copyDir(string $src, string $dst): void {
    if (!is_dir($dst)) mkdir($dst, 0755, true);
    foreach (scandir($src) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $s = "$src/$entry"; $d = "$dst/$entry";
        is_dir($s) ? copyDir($s, $d) : copy($s, $d);
    }
}

// ── Render one full-page list (.list files) ───────────────────────────────────

function renderFullList(array $listData): string {
    $columns = array_values(array_filter($listData['columns'] ?? [], fn($c) => ($c['showInListView'] ?? true) !== false));
    $items   = $listData['items'] ?? [];
    $views   = $listData['views'] ?? [];

    $e = fn($s) => htmlspecialchars((string)($s ?? ''));

    // Render all views as tabs, default view first
    $tabsHtml = '';
    $tabContentHtml = '';

    $allCols = array_values(array_filter($listData['columns'] ?? [], fn($c) => ($c['showInListView'] ?? true) !== false));

    $makeTable = function (array $cols, array $rows) use ($e): string {
        $h = '<table class="list-table"><thead><tr>';
        foreach ($cols as $col) $h .= '<th>' . $e($col['name']) . '</th>';
        $h .= '</tr></thead><tbody>';
        foreach ($rows as $item) {
            $h .= '<tr>';
            foreach ($cols as $col) $h .= '<td>' . $e($item[$col['id']] ?? '') . '</td>';
            $h .= '</tr>';
        }
        return $h . '</tbody></table>';
    };

    if (empty($views)) {
        return '<div class="inline-list-view" style="overflow-x:auto">' . $makeTable($allCols, $items) . '</div>';
    }

    $html = '<div class="static-list-views">';
    $html .= '<div class="static-view-tabs">';
    $html .= '<button class="static-view-tab active" onclick="switchTab(this,\'tab-default\')">All Items</button>';
    foreach ($views as $v) {
        $html .= '<button class="static-view-tab" onclick="switchTab(this,\'tab-' . htmlspecialchars($v['id']) . '\')">' . $e($v['name']) . '</button>';
    }
    $html .= '</div>';

    // All Items panel
    $html .= '<div id="tab-default" class="static-view-panel"><div style="overflow-x:auto">' . $makeTable($allCols, $items) . '</div></div>';

    // Named view panels
    foreach ($views as $v) {
        $vCols  = array_values(array_filter(array_map(fn($cid) =>
            current(array_filter($listData['columns'], fn($c) => $c['id'] === $cid)) ?: null,
            $v['columns'] ?? []
        )));
        $vItems = applyListFilters($items, $v);
        $html .= '<div id="tab-' . htmlspecialchars($v['id']) . '" class="static-view-panel" style="display:none"><div style="overflow-x:auto">' . $makeTable($vCols, $vItems) . '</div></div>';
    }

    $html .= '</div>';
    $html .= '<style>.static-view-tabs{display:flex;gap:8px;margin-bottom:15px}.static-view-tab{padding:4px 12px;border:1px solid var(--border-color);background:none;cursor:pointer;border-radius:4px;font-size:0.85em}.static-view-tab.active{background:var(--accent-blue);color:#fff;border-color:var(--accent-blue)}</style>';
    $html .= '<script>function switchTab(btn,id){document.querySelectorAll(".static-view-panel").forEach(p=>p.style.display="none");document.querySelectorAll(".static-view-tab").forEach(b=>b.classList.remove("active"));document.getElementById(id).style.display="";btn.classList.add("active");}<\/script>';

    return $html;
}

// ── Main export loop ──────────────────────────────────────────────────────────

echo "Building file tree…\n";
$tree    = buildTree($pagesDir, $pagesDir, $indexer);
$navHtml = renderNavTree($tree, 0); // placeholder; replaced per page

echo "Copying assets…\n";
copy(__DIR__ . '/styles.css', "$destDir/assets/styles.css");
copy(__DIR__ . '/logo.png',   "$destDir/assets/logo.png");

echo "Copying attachments…\n";
$dirIter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pagesDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($dirIter as $item) {
    if ($item->isDir() && str_ends_with($item->getFilename(), '.uploads')) {
        $rel = ltrim(str_replace($pagesDir, '', $item->getPathname()), '/');
        copyDir($item->getPathname(), "$destDir/files/$rel");
    }
}

echo "Exporting pages…\n";
$count = 0;
foreach ($allPages as $id => $pageData) {
    $relPath = $pageData['path'] ?? null;
    if (!$relPath) continue;
    $fullPath = "$pagesDir/$relPath";
    if (!file_exists($fullPath)) continue;

    $ext       = pathinfo($relPath, PATHINFO_EXTENSION);
    $pageTitle = preg_replace('/\.(md|drawio|list)$/', '', basename($relPath));
    $tags      = $pageData['tags'] ?? [];
    $tagsHtml  = '';
    if ($tags) {
        $tagsHtml = '<div class="static-tags">';
        foreach ($tags as $tag) $tagsHtml .= '<span class="static-tag">' . htmlspecialchars($tag) . '</span>';
        $tagsHtml .= '</div>';
    }

    $pageNavHtml = renderNavTree($tree, (int)$id);

    if ($ext === 'drawio') {
        echo "  [diagram] $relPath\n";
        $svg = getDiagramSvg($fullPath);
        $content = $svg
            ? '<div class="inline-diagram-viewer"><img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" style="max-width:100%;height:auto;" alt="' . htmlspecialchars($pageTitle) . '"></div>'
            : '<p>[Could not export diagram]</p>';

    } elseif ($ext === 'list') {
        echo "  [list]    $relPath\n";
        $listData = json_decode(file_get_contents($fullPath), true) ?? [];
        $content  = renderFullList($listData);

    } else {
        echo "  [md]      $relPath\n";
        $raw     = file_get_contents($fullPath);
        $mtime   = filemtime($fullPath);
        $raw     = processIncludes($raw, $idToPath, $pagesDir);
        $raw     = processDiagramTags($raw, $idToPath, $pagesDir);
        $raw     = processListTags($raw, $idToPath, $pagesDir);
        $html    = $parsedown->text($raw);
        $html    = str_replace('{filename}', htmlspecialchars($pageTitle), $html);
        $html    = str_replace('{lastUpdated}', date('Y-m-d H:i', $mtime), $html);
        $content = rewriteLinks($html, $idToPath);
    }

    $outPath = "$destDir/pages/page-$id.html";
    file_put_contents($outPath, renderHtmlPage($pageTitle, $content, $pageNavHtml, $tagsHtml));
    $count++;
}

// ── Index page (alphabetical page listing) ────────────────────────────────────

$listItems = '';
foreach ($allPages as $id => $pageData) {
    $path  = $pageData['path'] ?? null;
    if (!$path || !file_exists("$pagesDir/$path")) continue;
    $label = preg_replace('/\.(md|drawio|list)$/', '', $path);
    $listItems .= '<li><a href="pages/page-' . $id . '.html">' . htmlspecialchars($label) . '</a></li>';
}

$indexContent = '<h3>All Pages</h3><ul style="line-height:2">' . $listItems . '</ul>';
$indexNavHtml = renderNavTree($tree, 0, 'pages/');
file_put_contents(
    "$destDir/index.html",
    renderHtmlPage(APP_TITLE, $indexContent, $indexNavHtml, '', 'assets/')
);

echo "\nDone. Exported $count pages to: $destDir\n";
