<?php
/**
 * pdfuploader connector (restored core features, cleaned)
 * Goals:
 * - No hardcoded paths: ONLY MODX system settings
 * - Stable JSON responses (no HTML)
 * - Brands via SQL (ms2_vendors), products via SQL (ms2_products)
 * - MIGX TV read/write for documents
 *
 * Actions:
 *  - ping
 *  - list_vendors
 *  - lookup_product
 *  - search_all
 *  - list_folders
 *  - list_files
 *  - file_usage
 *  - upload_pdf
 *  - upload_pdf_mass
 *  - delete_registry
 *  - delete_migx_item
 *  - bulk_delete_registry_by_file
 *  - bulk_delete_migx_by_file
 */

define('MODX_API_MODE', true);

require_once dirname(__DIR__, 3) . '/config.core.php';
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

/** @var modX $modx */
$modx = new modX();
$modx->initialize('mgr');

/* ----------------------------- JSON / Errors ----------------------------- */

while (ob_get_level()) { @ob_end_clean(); }

function json_ok(array $data = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function json_error(string $message, array $extra = [], int $httpCode = 200): void {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => false,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(function(Throwable $e) use ($modx) {
    if ($modx) {
        $modx->log(modX::LOG_LEVEL_ERROR, '[pdfuploader] EXCEPTION: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    }
    json_error('Server error (exception). See MODX error log.', [
        'error' => $e->getMessage(),
        'file'  => basename($e->getFile()),
        'line'  => $e->getLine(),
    ]);
});
register_shutdown_function(function() use ($modx) {
    $err = error_get_last();
    if (!$err) return;
    if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    if ($modx) {
        $modx->log(modX::LOG_LEVEL_ERROR, '[pdfuploader] FATAL: ' . $err['message'] . ' at ' . $err['file'] . ':' . $err['line']);
    }
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Server error (fatal). See MODX error log.',
        'error'   => $err['message'],
        'file'    => basename((string)$err['file']),
        'line'    => (int)$err['line'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

/* ----------------------------- Auth guard ------------------------------ */

if (!$modx->user || !$modx->user->isAuthenticated('mgr')) {
    json_error('Forbidden', [], 403);
}

/* ----------------------------- Settings -------------------------------- */

function opt_trim(modX $modx, string $key): string {
    return trim((string)$modx->getOption($key), " \t\n\r\0\x0B/");
}
function abs_path(modX $modx, string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    if ($path[0] === '/') return rtrim($path, '/') . '/';
    $basePath = rtrim((string)$modx->getOption('base_path'), '/') . '/';
    $baseNoSlash = ltrim($basePath, '/');
    if (strpos($path, $baseNoSlash) === 0) return '/' . rtrim($path, '/') . '/';
    return $basePath . ltrim($path, '/') . '/';
}
function abs_url(modX $modx, string $u): string {
    $u = trim($u);
    if ($u === '') return '';
    if (preg_match('~^https?://~i', $u)) return rtrim($u, '/');
    $site = rtrim((string)$modx->getOption('site_url'), '/');
    return $site . '/' . ltrim($u, '/');
}
function join_url(string $base, string ...$parts): string {
    $out = rtrim($base, '/');
    foreach ($parts as $p) {
        $p = trim($p, '/');
        if ($p !== '') $out .= '/' . $p;
    }
    return $out;
}

$docsBasePath   = abs_path($modx, opt_trim($modx, 'pdfuploader.docs_base_path'));
$docsBaseUrl    = abs_url($modx, opt_trim($modx, 'pdfuploader.docs_base_url'));
$thumbsBasePath = abs_path($modx, opt_trim($modx, 'pdfuploader.thumbs_base_path'));
$thumbsBaseUrl  = abs_url($modx, opt_trim($modx, 'pdfuploader.thumbs_base_url'));

$missing = [];
if ($docsBasePath === '')   $missing[] = 'pdfuploader.docs_base_path';
if ($docsBaseUrl === '')    $missing[] = 'pdfuploader.docs_base_url';
if ($thumbsBasePath === '') $missing[] = 'pdfuploader.thumbs_base_path';
if ($thumbsBaseUrl === '')  $missing[] = 'pdfuploader.thumbs_base_url';
if ($missing) json_error('Missing system settings: ' . implode(', ', $missing));

$defaultFolder = (string)$modx->getOption('pdfuploader.default_folder', null, 'manuals');
$tvDefault     = (string)$modx->getOption('pdfuploader.tv_name', null, 'sertif');
$registryName  = (string)$modx->getOption('pdfuploader.registry_table', null, 'pdfuploader_registry');
$useRegistry   = (int)$modx->getOption('pdfuploader.use_registry', null, 1);

$registryTable = (string)$modx->getOption('table_prefix', null, '') . $registryName;

/* ----------------------------- Helpers --------------------------------- */

function getStr(array $a, string $k, string $d=''): string { return isset($a[$k]) ? (string)$a[$k] : $d; }
function getInt(array $a, string $k, int $d=0): int { return isset($a[$k]) ? (int)$a[$k] : $d; }

function normFolder(string $s): string {
    $s = trim($s);
    $s = preg_replace('~\s*\(авто\)$~u', '', $s);
    $s = trim($s, "/\\ \t\n\r\0\x0B");
    // allow nested folders but only safe chars and slashes
    $s = preg_replace('~[^a-z0-9_\-/]+~i', '', $s);
    $s = preg_replace('~/{2,}~', '/', $s);
    return trim($s, '/');
}
function ensureDir(string $dir): bool {
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0775, true) || is_dir($dir);
}
function slugify_filename(string $name, string $forceExt = ''): string {
    $name = trim($name);
    $name = str_replace(['\\','/'], '-', $name);
    $name = preg_replace('~\s+~u', '-', $name);

    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $base = pathinfo($name, PATHINFO_FILENAME);

    // translit best-effort
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base);
    if ($t !== false) $base = $t;

    $base = strtolower($base);
    $base = preg_replace('~[^a-z0-9\-_\.]+~', '-', $base);
    $base = trim(preg_replace('~-+~','-',$base), '-_.');
    if ($base === '') $base = 'file';

    if ($forceExt !== '') $ext = strtolower($forceExt);
    if ($ext === '') $ext = 'pdf';

    return $base . '.' . $ext;
}
function parse_articles_list(string $s): array {
    $s = trim($s);
    if ($s === '') return [];
    $lines = preg_split('~[\r\n,; ]+~', $s);
    $out = [];
    foreach ($lines as $x) {
        $x = trim($x);
        if ($x === '') continue;
        $out[$x] = true;
    }
    return array_keys($out);
}

/**
 * Normalizes "file" from MIGX: expects "folder/name.pdf".
 * If stored without folder, assumes default folder.
 */
function normalizeRel(string $file, string $defaultFolder): array {
    $f = trim($file, "/\\ \t\n\r\0\x0B");
    $f = str_replace('\\','/',$f);
    if ($f === '') return ['folder'=>'', 'name'=>'', 'rel'=>''];
    if (strpos($f,'/') === false) {
        $folder = normFolder($defaultFolder);
        $name = basename($f);
        return ['folder'=>$folder, 'name'=>$name, 'rel'=>($folder!=='' ? $folder.'/' : '') . $name];
    }
    $folder = normFolder(dirname($f));
    $name = basename($f);
    return ['folder'=>$folder, 'name'=>$name, 'rel'=>($folder!=='' ? $folder.'/' : '') . $name];
}

/** Preview WEBP from first PDF page using Imagick (if available). */
function makeWebpPreview(string $pdfPath, string $thumbPath, array &$diag): bool {
    if (!class_exists('Imagick')) {
        $diag[] = 'Imagick not available';
        return false;
    }
    try {
        $im = new Imagick();
        $im->setResolution(160, 160);
        $im->readImage($pdfPath . '[0]');
        $im->setImageFormat('webp');
        $im->setImageCompressionQuality(75);
        $im->thumbnailImage(600, 0);
        $ok = $im->writeImage($thumbPath);
        $im->clear();
        $im->destroy();
        return (bool)$ok && is_file($thumbPath) && filesize($thumbPath) > 0;
    } catch (Throwable $e) {
        $diag[] = 'preview failed: ' . $e->getMessage();
        return false;
    }
}

/* ----------------------------- MIGX helpers ----------------------------- */

function migx_get_items(modX $modx, int $rid, string $tvName): array {
    $tv = $modx->getObject('modTemplateVar', ['name'=>$tvName]);
    if (!$tv) return [];
    $current = (string)$tv->getValue($rid);
    $items = $current ? json_decode($current, true) : [];
    return is_array($items) ? $items : [];
}

function migx_append(modX $modx, int $rid, string $tvName, array $row): bool {
    $tv = $modx->getObject('modTemplateVar', ['name'=>$tvName]);
    if (!$tv) return false;

    $items = migx_get_items($modx, $rid, $tvName);
    $items[] = $row;

    $tvId = (int)$tv->get('id');
    /** @var modTemplateVarResource $tvr */
    $tvr = $modx->getObject('modTemplateVarResource', ['contentid'=>$rid,'tmplvarid'=>$tvId]);
    if (!$tvr) {
        $tvr = $modx->newObject('modTemplateVarResource');
        $tvr->set('contentid', $rid);
        $tvr->set('tmplvarid', $tvId);
    }
    $tvr->set('value', json_encode($items, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    return (bool)$tvr->save();
}

function migx_remove_by_file(modX $modx, int $rid, string $tvName, string $relFile, int &$removed): bool {
    $removed = 0;
    $tv = $modx->getObject('modTemplateVar', ['name'=>$tvName]);
    if (!$tv) return false;

    $tvId = (int)$tv->get('id');
    /** @var modTemplateVarResource $tvr */
    $tvr = $modx->getObject('modTemplateVarResource', ['contentid'=>$rid,'tmplvarid'=>$tvId]);
    if (!$tvr) return true;

    $val = (string)$tvr->get('value');
    if ($val === '') return true;

    $items = json_decode($val, true);
    if (!is_array($items)) return true;

    $out = [];
    foreach ($items as $it) {
        $nf = normalizeRel((string)($it['file'] ?? ''), '');
        if ($nf['rel'] === $relFile) { $removed++; continue; }
        $out[] = $it;
    }

    if ($removed > 0) {
        $tvr->set('value', json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return (bool)$tvr->save();
    }
    return true;
}

/* ----------------------------- Routing ---------------------------------- */

$action = getStr($_REQUEST, 'action', '');

if ($action === '' || $action === 'ping') {
    json_ok([
        'success' => true,
        'paths' => [
            'docs_base_path'   => rtrim($docsBasePath, "/\\"),
            'docs_base_url'    => $docsBaseUrl,
            'thumbs_base_path' => rtrim($thumbsBasePath, "/\\"),
            'thumbs_base_url'  => $thumbsBaseUrl,
        ],
        'tv_default' => $tvDefault,
        'default_folder' => $defaultFolder,
        'use_registry' => $useRegistry,
        'registry_table' => $registryTable,
    ]);
}

/* ----------------------------- Brands / Products ------------------------- */

if ($action === 'list_vendors') {
    $pfx = (string)$modx->getOption('table_prefix', null, '');
    $tv  = $pfx . 'ms2_vendors';

    $st = $modx->prepare("SELECT id, name FROM `{$tv}` ORDER BY name ASC");
    if (!$st || !$st->execute()) json_ok(['success'=>false,'message'=>'vendors sql failed','errorInfo'=>$st ? $st->errorInfo() : null]);

    $items = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) $items[] = ['id'=>(int)$r['id'],'name'=>(string)$r['name']];
    json_ok(['success'=>true,'items'=>$items,'count'=>count($items)]);
}

if ($action === 'lookup_product') {
    $rid = getInt($_REQUEST,'rid',0);
    $article = getStr($_REQUEST,'article','');
    $vendorFilter = getInt($_REQUEST,'vendor_id',0);

    $pfx = (string)$modx->getOption('table_prefix', null, '');
    $tp  = $pfx . 'ms2_products';
    $tv  = $pfx . 'ms2_vendors';

    if ($rid <= 0 && $article === '') json_ok(['success'=>false,'message'=>'Specify rid or article','items'=>[]]);

    $sql = "SELECT p.id, p.pagetitle, p.article, p.vendor AS vendor_id, v.name AS vendor_name
            FROM `{$tp}` p
            LEFT JOIN `{$tv}` v ON v.id = p.vendor
            WHERE 1=1";
    $params = [];

    if ($rid > 0) { $sql .= " AND p.id = :id"; $params[':id'] = $rid; }
    if ($article !== '') { $sql .= " AND p.article = :a"; $params[':a'] = $article; }
    if ($vendorFilter > 0) { $sql .= " AND p.vendor = :vid"; $params[':vid'] = $vendorFilter; }

    $sql .= " ORDER BY p.id ASC LIMIT 50";

    $st = $modx->prepare($sql);
    if (!$st) json_ok(['success'=>false,'message'=>'prepare failed']);
    foreach ($params as $k=>$v) {
        $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    if (!$st->execute()) json_ok(['success'=>false,'message'=>'execute failed','errorInfo'=>$st->errorInfo()]);

    $items = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $items[] = [
            'id' => (int)$r['id'],
            'pagetitle' => (string)$r['pagetitle'],
            'article' => (string)$r['article'],
            'vendor_id' => (int)$r['vendor_id'],
            'vendor_name' => (string)($r['vendor_name'] ?? ''),
        ];
    }
    json_ok(['success'=>true,'items'=>$items,'count'=>count($items)]);
}

/* ----------------------------- Search & Usage ---------------------------- */

if ($action === 'search_all') {
    $article    = getStr($_REQUEST,'article','');
    $vendorId   = getInt($_REQUEST,'vendor_id',0);
    $resourceId = getInt($_REQUEST,'resource_id',0);
    $folder     = normFolder(getStr($_REQUEST,'folder',$defaultFolder));
    $tvName     = getStr($_REQUEST,'tv_name',$tvDefault);

    // resolve resource_id from article+vendor if needed
    if ($resourceId <= 0 && $article !== '') {
        $pfx = (string)$modx->getOption('table_prefix', null, '');
        $tp  = $pfx . 'ms2_products';

        $sql = "SELECT id, vendor FROM `{$tp}` WHERE article=:a";
        if ($vendorId > 0) $sql .= " AND vendor=:v";
        $sql .= " ORDER BY id ASC LIMIT 1";

        $st = $modx->prepare($sql);
        $st->bindValue(':a',$article);
        if ($vendorId > 0) $st->bindValue(':v',$vendorId,PDO::PARAM_INT);
        if ($st && $st->execute()) {
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $resourceId = (int)$row['id'];
                if ($vendorId <= 0) $vendorId = (int)$row['vendor'];
            }
        }
    }

    $fromRegistry = [];
    if ($useRegistry && $article !== '') {
        $st = $modx->prepare("SELECT id,resource_id,article,vendor_id,vendor_name,folder,file,image,pdf_url,thumb_url,createdon
                              FROM `{$registryTable}`
                              WHERE article=:a" . ($vendorId>0 ? " AND vendor_id=:v" : "") . "
                              ORDER BY createdon DESC");
        if ($st) {
            $st->bindValue(':a',$article);
            if ($vendorId>0) $st->bindValue(':v',$vendorId,PDO::PARAM_INT);
            if ($st->execute()) {
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) $fromRegistry[] = $r;
            }
        }
    }

    $fromMigx = [];
    if ($resourceId > 0) {
        $items = migx_get_items($modx, $resourceId, $tvName);
        foreach ($items as $it) {
            $file = (string)($it['file'] ?? '');
            if ($file === '') continue;
            $nf = normalizeRel($file, $defaultFolder);
            $folder2 = $nf['folder'] !== '' ? $nf['folder'] : $folder;
            $name = $nf['name'];

            $thumb = (string)($it['image'] ?? '');
            $thumbN = $thumb !== '' ? normalizeRel($thumb, $defaultFolder)['name'] : ($name ? preg_replace('~\.pdf$~i','.webp',$name) : '');
            $thumbRel = ($thumbN !== '' ? ($folder2!==''?$folder2.'/':'') . $thumbN : '');

            // vendor_name for resource (SQL)
            $vendorName = '';
            $vId = $vendorId;
            if ($vId <= 0) {
                $pfx = (string)$modx->getOption('table_prefix', null, '');
                $tp  = $pfx . 'ms2_products';
                $st = $modx->prepare("SELECT vendor FROM `{$tp}` WHERE id=:id LIMIT 1");
                if ($st) { $st->bindValue(':id',$resourceId,PDO::PARAM_INT); if ($st->execute()) $vId = (int)$st->fetchColumn(); }
            }
            if ($vId > 0) {
                $pfx = (string)$modx->getOption('table_prefix', null, '');
                $tv  = $pfx . 'ms2_vendors';
                $st = $modx->prepare("SELECT name FROM `{$tv}` WHERE id=:id LIMIT 1");
                if ($st) { $st->bindValue(':id',$vId,PDO::PARAM_INT); if ($st->execute()) $vendorName = (string)$st->fetchColumn(); }
            }

            $fromMigx[] = [
                'resource_id' => $resourceId,
                'article' => $article,
                'vendor_id' => $vId,
                'vendor_name' => $vendorName,
                'folder' => $folder2,
                'file' => ($folder2!=='' ? $folder2.'/' : '') . $name,
                'image' => $thumbRel,
                'pdf_url' => join_url($docsBaseUrl, $folder2, $name),
                'thumb_url' => ($thumbRel!=='' ? join_url($thumbsBaseUrl, $folder2, $thumbN) : ''),
            ];
        }
    }

    json_ok([
        'success' => true,
        'from_registry' => $fromRegistry,
        'from_migx' => $fromMigx,
        'diagnostics' => [],
    ]);
}

if ($action === 'file_usage') {
    $file = trim(getStr($_REQUEST,'file',''), "/\\");
    $tvName = getStr($_REQUEST,'tv_name',$tvDefault);
    if ($file === '') json_ok(['success'=>false,'message'=>'Specify file']);

    $nf = normalizeRel($file, $defaultFolder);
    $folder = $nf['folder'];
    $name = $nf['name'];
    $relative = $nf['rel'];

    // Registry usage
    $registry = [];
    if ($useRegistry) {
        $st = $modx->prepare("SELECT id,article,vendor_id,vendor_name,folder,pdf_name,thumb_name,pdf_url,thumb_url,createdon
                              FROM `{$registryTable}`
                              WHERE folder=:f AND pdf_name=:n
                              ORDER BY createdon DESC");
        if ($st) {
            $st->bindValue(':f',$folder);
            $st->bindValue(':n',$name);
            if ($st->execute()) {
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                    $folder2 = normFolder((string)($r['folder'] ?? $folder));
                    $pdfName = (string)($r['pdf_name'] ?? $name);
                    $thumbName = (string)($r['thumb_name'] ?? '');
                    $registry[] = [
                        'id'=>(int)$r['id'],
                        'article'=>(string)$r['article'],
                        'vendor_id'=>(int)$r['vendor_id'],
                        'vendor_name'=>(string)$r['vendor_name'],
                        'folder'=>$folder2,
                        'pdf_name'=>$pdfName,
                        'thumb_name'=>$thumbName,
                        'pdf_url'=> join_url($docsBaseUrl, $folder2, $pdfName),
                        'thumb_url'=> ($thumbName !== '' ? join_url($thumbsBaseUrl, $folder2, $thumbName) : ''),
                        'createdon'=>(string)$r['createdon'],
                    ];
                }
            }
        }
    }

    // MIGX usage: LIKE then confirm JSON
    $migx = [];
    $tv = $modx->getObject('modTemplateVar', ['name'=>$tvName]);
    if (!$tv) json_ok(['success'=>true,'registry'=>$registry,'migx'=>[],'file'=>$relative,'count'=>count($registry),'diagnostics'=>["tv '{$tvName}' not found"]]);

    $tvId = (int)$tv->get('id');
    $q = $modx->newQuery('modTemplateVarResource');
    $q->select(['contentid','value']);
    $q->where(['tmplvarid'=>$tvId,'value:LIKE'=>'%'.$relative.'%']);

    $hits = [];
    if ($q->prepare() && $q->stmt->execute()) {
        while ($row = $q->stmt->fetch(PDO::FETCH_ASSOC)) {
            $rid = (int)$row['contentid'];
            $items = json_decode((string)$row['value'], true);
            if (!is_array($items)) continue;
            foreach ($items as $it) {
                $nf2 = normalizeRel((string)($it['file'] ?? ''), $defaultFolder);
                if ($nf2['rel'] === $relative) { $hits[$rid] = true; break; }
            }
        }
    }

    if ($hits) {
        $ids = array_keys($hits);
        $pfx = (string)$modx->getOption('table_prefix', null, '');
        $tp  = $pfx . 'ms2_products';
        $tvv = $pfx . 'ms2_vendors';
        $in = implode(',', array_fill(0, count($ids), '?'));

        $sql = "SELECT p.id, p.pagetitle, p.article, p.vendor AS vendor_id, v.name AS vendor_name
                FROM `{$tp}` p
                LEFT JOIN `{$tvv}` v ON v.id = p.vendor
                WHERE p.id IN ({$in})";

        $st = $modx->prepare($sql);
        foreach ($ids as $i=>$id) $st->bindValue($i+1, (int)$id, PDO::PARAM_INT);
        if ($st && $st->execute()) {
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $migx[] = [
                    'resource_id'=>(int)$r['id'],
                    'pagetitle'=>(string)$r['pagetitle'],
                    'article'=>(string)$r['article'],
                    'vendor_id'=>(int)$r['vendor_id'],
                    'vendor_name'=>(string)($r['vendor_name'] ?? ''),
                    'folder'=>$folder,
                    'file'=>$relative,
                    'pdf_url'=> join_url($docsBaseUrl, $folder, $name),
                ];
            }
        }
    }

    json_ok(['success'=>true,'registry'=>$registry,'migx'=>$migx,'file'=>$relative,'count'=>count($migx)+count($registry)]);
}

/* ----------------------------- Browsing --------------------------------- */

if ($action === 'list_folders') {
    $folders = [];
    $base = rtrim($docsBasePath, "/\\");
    if (is_dir($base)) {
        $it = new DirectoryIterator($base);
        foreach ($it as $f) {
            if ($f->isDot() || !$f->isDir()) continue;
            $folders[] = $f->getFilename();
        }
        sort($folders, SORT_NATURAL | SORT_FLAG_CASE);
    }
    json_ok(['success'=>true,'folders'=>$folders]);
}

if ($action === 'list_files') {
    $folder = normFolder(getStr($_REQUEST,'folder',$defaultFolder));
    $dir = rtrim($docsBasePath, "/\\") . '/' . $folder;
    $files = [];
    if (is_dir($dir)) {
        $it = new DirectoryIterator($dir);
        foreach ($it as $f) {
            if ($f->isDot() || !$f->isFile()) continue;
            $files[] = $f->getFilename();
        }
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    }
    json_ok(['success'=>true,'folder'=>$folder,'files'=>$files]);
}

/* -------------------------- Upload / Delete ------------------------------ */

if ($action === 'upload_pdf') {
    $folder     = normFolder(getStr($_POST,'folder', $defaultFolder));
    $article    = getStr($_POST,'article','');
    $vendorId   = getInt($_POST,'vendor_id',0);
    $title      = getStr($_POST,'title','');
    $resourceId = getInt($_POST,'resource_id',0);
    $tvName     = getStr($_POST,'tv_name', $tvDefault);

    // resolve resource_id by article+vendor if needed
    if ($resourceId <= 0 && $article !== '') {
        $pfx = (string)$modx->getOption('table_prefix', null, '');
        $tp  = $pfx . 'ms2_products';
        $sql = "SELECT id, vendor FROM `{$tp}` WHERE article=:a";
        if ($vendorId > 0) $sql .= " AND vendor=:v";
        $sql .= " ORDER BY id ASC LIMIT 1";

        $st = $modx->prepare($sql);
        $st->bindValue(':a', $article);
        if ($vendorId > 0) $st->bindValue(':v', $vendorId, PDO::PARAM_INT);
        if ($st && $st->execute()) {
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $resourceId = (int)$row['id'];
                if ($vendorId <= 0) $vendorId = (int)$row['vendor'];
            }
        }
    }

    if (empty($_FILES['pdf_file']['tmp_name'])) json_ok(['success'=>false,'message'=>'Файл не получен']);
    $origName = basename($_FILES['pdf_file']['name'] ?? 'file.pdf');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') json_ok(['success'=>false,'message'=>'Допустим только PDF']);

    $docsDir   = rtrim($docsBasePath,'/\\') . '/' . $folder . '/';
    $thumbsDir = rtrim($thumbsBasePath,'/\\') . '/' . $folder . '/';
    if (!ensureDir($docsDir) || !ensureDir($thumbsDir)) json_ok(['success'=>false,'message'=>'Не удалось создать каталоги']);

    $pdfName = slugify_filename($origName, 'pdf');
    $pdfPath = $docsDir . $pdfName;
    if (file_exists($pdfPath)) {
        $base = pathinfo($pdfName, PATHINFO_FILENAME) . '-' . time();
        $pdfName = slugify_filename($base.'.pdf','pdf');
        $pdfPath = $docsDir . $pdfName;
    }
    if (!move_uploaded_file($_FILES['pdf_file']['tmp_name'], $pdfPath)) json_ok(['success'=>false,'message'=>'Не удалось сохранить PDF']);

    $thumbName = preg_replace('~\.pdf$~i', '.webp', $pdfName);
    $thumbPath = $thumbsDir . $thumbName;

    $diag = [];
    $thumbOk = makeWebpPreview($pdfPath, $thumbPath, $diag);

    $relativePdf   = ($folder !== '' ? $folder.'/' : '') . $pdfName;
    $relativeThumb = $thumbOk ? (($folder !== '' ? $folder.'/' : '') . $thumbName) : '';

    $pdfUrl   = join_url($docsBaseUrl, $folder, $pdfName);
    $thumbUrl = $thumbOk ? join_url($thumbsBaseUrl, $folder, $thumbName) : '';

    // vendor name via SQL
    $vendorName = '';
    if ($vendorId > 0) {
        $pfx = (string)$modx->getOption('table_prefix', null, '');
        $tvn = $pfx . 'ms2_vendors';
        $st = $modx->prepare("SELECT name FROM `{$tvn}` WHERE id=:id LIMIT 1");
        if ($st) { $st->bindValue(':id',$vendorId,PDO::PARAM_INT); if ($st->execute()) $vendorName = (string)$st->fetchColumn(); }
    }

    // update MIGX
    $migxUpdated = false;
    if ($resourceId > 0) {
        $migxUpdated = migx_append($modx, $resourceId, $tvName, [
            'file'  => $relativePdf,
            'image' => $relativeThumb,
            'title' => $title,
        ]);
    }

    // registry insert (best-effort)
    if ($useRegistry) {
        try {
            $st = $modx->prepare("INSERT INTO `{$registryTable}` (resource_id,article,vendor_id,vendor_name,folder,file,image,pdf_url,thumb_url,createdon)
                                  VALUES (:rid,:a,:v,:vn,:f,:file,:img,:pu,:tu,NOW())");
            if ($st) {
                $st->bindValue(':rid',$resourceId,PDO::PARAM_INT);
                $st->bindValue(':a',$article);
                $st->bindValue(':v',$vendorId,PDO::PARAM_INT);
                $st->bindValue(':vn',$vendorName);
                $st->bindValue(':f',$folder);
                $st->bindValue(':file',$relativePdf);
                $st->bindValue(':img',$relativeThumb);
                $st->bindValue(':pu',$pdfUrl);
                $st->bindValue(':tu',$thumbUrl);
                $st->execute();
            }
        } catch (Throwable $e) {
            $diag[] = 'registry insert failed: '.$e->getMessage();
        }
    }

    json_ok([
        'success'=>true,
        'resource_id'=>$resourceId,
        'article'=>$article,
        'vendor_id'=>$vendorId,
        'vendor_name'=>$vendorName,
        'folder'=>$folder,
        'file'=>$relativePdf,
        'image'=>$relativeThumb,
        'pdf_url'=>$pdfUrl,
        'thumb_url'=>$thumbUrl,
        'migx_updated'=>$migxUpdated,
        'diagnostics'=>$diag,
    ]);
}

/**
 * Mass upload supports TWO modes (backwards-friendly):
 * Mode A: multiple files in pdf_files[]; article inferred from filename (digits in name or base name).
 * Mode B: one file pdf_file + POST['articles'] list -> attach same file (copied) per article.
 */
if ($action === 'upload_pdf_mass') {
    $folder = normFolder(getStr($_POST,'folder', $defaultFolder));
    $tvName = getStr($_POST,'tv_name', $tvDefault);
    $vendorId = getInt($_POST,'vendor_id',0);

    $docsDir   = rtrim($docsBasePath,'/\\') . '/' . $folder . '/';
    $thumbsDir = rtrim($thumbsBasePath,'/\\') . '/' . $folder . '/';
    if (!ensureDir($docsDir) || !ensureDir($thumbsDir)) json_ok(['success'=>false,'message'=>'Не удалось создать каталоги']);

    $results = [];
    $diag = [];

    // Mode B: single file + articles list
    $articlesList = parse_articles_list(getStr($_POST,'articles',''));
    if (!empty($_FILES['pdf_file']['tmp_name']) && $articlesList) {
        $origName = basename($_FILES['pdf_file']['name'] ?? 'file.pdf');
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') json_ok(['success'=>false,'message'=>'Допустим только PDF']);

        $tmpSrc = $_FILES['pdf_file']['tmp_name'];
        if (!is_uploaded_file($tmpSrc)) json_ok(['success'=>false,'message'=>'Не удалось прочитать загруженный файл']);

        foreach ($articlesList as $i=>$article) {
            // resolve resource_id by article+vendor
            $resourceId = 0;
            $v = $vendorId;

            $pfx = (string)$modx->getOption('table_prefix', null, '');
            $tp  = $pfx . 'ms2_products';
            $sql = "SELECT id, vendor FROM `{$tp}` WHERE article=:a";
            if ($v > 0) $sql .= " AND vendor=:v";
            $sql .= " ORDER BY id ASC LIMIT 1";
            $st = $modx->prepare($sql);
            $st->bindValue(':a',$article);
            if ($v > 0) $st->bindValue(':v',$v,PDO::PARAM_INT);
            if ($st && $st->execute()) {
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) { $resourceId = (int)$row['id']; if ($v<=0) $v=(int)$row['vendor']; }
            }

            if ($resourceId <= 0) {
                $results[] = ['article'=>$article,'success'=>false,'message'=>'Товар не найден (article/vendor)'];
                continue;
            }

            // file name based on article
            $pdfName = slugify_filename($article.'.pdf','pdf');
            $pdfPath = $docsDir . $pdfName;
            if (file_exists($pdfPath)) {
                $pdfName = slugify_filename($article.'-'.time().'-'.$i.'.pdf','pdf');
                $pdfPath = $docsDir . $pdfName;
            }

            if (!copy($tmpSrc, $pdfPath)) {
                $results[] = ['article'=>$article,'success'=>false,'message'=>'Не удалось сохранить PDF'];
                continue;
            }

            $thumbName = preg_replace('~\.pdf$~i', '.webp', $pdfName);
            $thumbPath = $thumbsDir . $thumbName;
            $d = [];
            $thumbOk = makeWebpPreview($pdfPath, $thumbPath, $d);

            $relativePdf   = ($folder !== '' ? $folder.'/' : '') . $pdfName;
            $relativeThumb = $thumbOk ? (($folder !== '' ? $folder.'/' : '') . $thumbName) : '';

            $pdfUrl   = join_url($docsBaseUrl, $folder, $pdfName);
            $thumbUrl = $thumbOk ? join_url($thumbsBaseUrl, $folder, $thumbName) : '';

            $okMigx = migx_append($modx, $resourceId, $tvName, [
                'file'=>$relativePdf,
                'image'=>$relativeThumb,
                'title'=>getStr($_POST,'title',''),
            ]);

            if ($useRegistry) {
                try {
                    $vendorName = '';
                    if ($v > 0) {
                        $tvn = $pfx . 'ms2_vendors';
                        $st2 = $modx->prepare("SELECT name FROM `{$tvn}` WHERE id=:id LIMIT 1");
                        if ($st2) { $st2->bindValue(':id',$v,PDO::PARAM_INT); if ($st2->execute()) $vendorName = (string)$st2->fetchColumn(); }
                    }

                    $st3 = $modx->prepare("INSERT INTO `{$registryTable}` (resource_id,article,vendor_id,vendor_name,folder,file,image,pdf_url,thumb_url,createdon)
                                           VALUES (:rid,:a,:v,:vn,:f,:file,:img,:pu,:tu,NOW())");
                    if ($st3) {
                        $st3->bindValue(':rid',$resourceId,PDO::PARAM_INT);
                        $st3->bindValue(':a',$article);
                        $st3->bindValue(':v',$v,PDO::PARAM_INT);
                        $st3->bindValue(':vn',$vendorName);
                        $st3->bindValue(':f',$folder);
                        $st3->bindValue(':file',$relativePdf);
                        $st3->bindValue(':img',$relativeThumb);
                        $st3->bindValue(':pu',$pdfUrl);
                        $st3->bindValue(':tu',$thumbUrl);
                        $st3->execute();
                    }
                } catch (Throwable $e) {
                    $d[] = 'registry insert failed: '.$e->getMessage();
                }
            }

            $results[] = [
                'article'=>$article,
                'resource_id'=>$resourceId,
                'vendor_id'=>$v,
                'file'=>$relativePdf,
                'thumb'=>$relativeThumb,
                'success'=>true,
                'migx_updated'=>$okMigx,
                'diagnostics'=>$d,
            ];
        }

        json_ok(['success'=>true,'mode'=>'single_file_to_articles','results'=>$results]);
    }

    // Mode A: multiple files pdf_files[]
    if (empty($_FILES['pdf_files'])) json_ok(['success'=>false,'message'=>'Файлы не получены (pdf_files[])']);

    $names = $_FILES['pdf_files']['name'] ?? [];
    $tmps  = $_FILES['pdf_files']['tmp_name'] ?? [];
    $cnt = is_array($names) ? count($names) : 0;

    for ($i=0; $i<$cnt; $i++) {
        $origName = basename($names[$i] ?? 'file.pdf');
        $tmp = $tmps[$i] ?? '';
        if ($tmp === '' || !is_uploaded_file($tmp)) continue;

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') { $results[]=['name'=>$origName,'success'=>false,'message'=>'not pdf']; continue; }

        $base = pathinfo($origName, PATHINFO_FILENAME);

        // infer article: first long digit chunk, else full base
        $article = '';
        if (preg_match('~(\d{4,})~', $base, $m)) $article = $m[1];
        else $article = $base;

        $resourceId = 0;
        $v = $vendorId;

        // resolve product by article+vendor
        $pfx = (string)$modx->getOption('table_prefix', null, '');
        $tp  = $pfx . 'ms2_products';
        $sql = "SELECT id, vendor FROM `{$tp}` WHERE article=:a";
        if ($v > 0) $sql .= " AND vendor=:v";
        $sql .= " ORDER BY id ASC LIMIT 1";
        $st = $modx->prepare($sql);
        $st->bindValue(':a',$article);
        if ($v > 0) $st->bindValue(':v',$v,PDO::PARAM_INT);
        if ($st && $st->execute()) {
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) { $resourceId = (int)$row['id']; if ($v<=0) $v=(int)$row['vendor']; }
        }

        $pdfName = slugify_filename($origName, 'pdf');
        $pdfPath = $docsDir . $pdfName;
        if (file_exists($pdfPath)) {
            $pdfName = slugify_filename($base.'-'.time().'-'.$i.'.pdf','pdf');
            $pdfPath = $docsDir . $pdfName;
        }
        if (!move_uploaded_file($tmp, $pdfPath)) {
            $results[] = ['name'=>$origName,'article'=>$article,'success'=>false,'message'=>'move_uploaded_file failed'];
            continue;
        }

        $thumbName = preg_replace('~\.pdf$~i', '.webp', $pdfName);
        $thumbPath = $thumbsDir . $thumbName;
        $d = [];
        $thumbOk = makeWebpPreview($pdfPath, $thumbPath, $d);

        $relativePdf   = ($folder !== '' ? $folder.'/' : '') . $pdfName;
        $relativeThumb = $thumbOk ? (($folder !== '' ? $folder.'/' : '') . $thumbName) : '';

        $pdfUrl   = join_url($docsBaseUrl, $folder, $pdfName);
        $thumbUrl = $thumbOk ? join_url($thumbsBaseUrl, $folder, $thumbName) : '';

        $okMigx = false;
        if ($resourceId > 0) {
            $okMigx = migx_append($modx, $resourceId, $tvName, [
                'file'=>$relativePdf,
                'image'=>$relativeThumb,
                'title'=>getStr($_POST,'title',''),
            ]);
        } else {
            $d[] = 'product not found by inferred article/vendor; migx not updated';
        }

        if ($useRegistry) {
            try {
                $vendorName = '';
                if ($v > 0) {
                    $tvn = $pfx . 'ms2_vendors';
                    $st2 = $modx->prepare("SELECT name FROM `{$tvn}` WHERE id=:id LIMIT 1");
                    if ($st2) { $st2->bindValue(':id',$v,PDO::PARAM_INT); if ($st2->execute()) $vendorName = (string)$st2->fetchColumn(); }
                }

                $st3 = $modx->prepare("INSERT INTO `{$registryTable}` (resource_id,article,vendor_id,vendor_name,folder,file,image,pdf_url,thumb_url,createdon)
                                       VALUES (:rid,:a,:v,:vn,:f,:file,:img,:pu,:tu,NOW())");
                if ($st3) {
                    $st3->bindValue(':rid',$resourceId,PDO::PARAM_INT);
                    $st3->bindValue(':a',$article);
                    $st3->bindValue(':v',$v,PDO::PARAM_INT);
                    $st3->bindValue(':vn',$vendorName);
                    $st3->bindValue(':f',$folder);
                    $st3->bindValue(':file',$relativePdf);
                    $st3->bindValue(':img',$relativeThumb);
                    $st3->bindValue(':pu',$pdfUrl);
                    $st3->bindValue(':tu',$thumbUrl);
                    $st3->execute();
                }
            } catch (Throwable $e) {
                $d[] = 'registry insert failed: '.$e->getMessage();
            }
        }

        $results[] = [
            'name'=>$origName,
            'article'=>$article,
            'resource_id'=>$resourceId,
            'vendor_id'=>$v,
            'file'=>$relativePdf,
            'thumb'=>$relativeThumb,
            'pdf_url'=>$pdfUrl,
            'thumb_url'=>$thumbUrl,
            'success'=>true,
            'migx_updated'=>$okMigx,
            'diagnostics'=>$d,
        ];
    }

    json_ok(['success'=>true,'mode'=>'multi_files','folder'=>$folder,'results'=>$results]);
}

if ($action === 'delete_registry') {
    if (!$useRegistry) json_ok(['success'=>false,'message'=>'Registry disabled']);
    $id = getInt($_REQUEST,'id',0);
    if ($id <= 0) json_ok(['success'=>false,'message'=>'Specify id']);
    $st = $modx->prepare("DELETE FROM `{$registryTable}` WHERE id=:id");
    $st->bindValue(':id',$id,PDO::PARAM_INT);
    $ok = $st->execute();
    json_ok(['success'=>(bool)$ok,'deleted'=>$st->rowCount()]);
}

if ($action === 'delete_migx_item') {
    $rid = getInt($_REQUEST,'resource_id',0);
    $tvName = getStr($_REQUEST,'tv_name',$tvDefault);
    $file = trim(getStr($_REQUEST,'file',''),'/');
    if ($rid<=0 || $file==='') json_ok(['success'=>false,'message'=>'Specify resource_id and file']);
    $removed = 0;
    $ok = migx_remove_by_file($modx, $rid, $tvName, $file, $removed);
    json_ok(['success'=>(bool)$ok,'removed'=>$removed]);
}

if ($action === 'bulk_delete_registry_by_file') {
    if (!$useRegistry) json_ok(['success'=>false,'message'=>'Registry disabled']);
    $folder = normFolder(getStr($_REQUEST,'folder',''));
    $name   = trim(getStr($_REQUEST,'name',''));
    if ($folder==='' || $name==='') json_ok(['success'=>false,'message'=>'Specify folder and name']);
    $fileRel = $folder . '/' . $name;
    $st = $modx->prepare("DELETE FROM `{$registryTable}` WHERE file=:file");
    $st->bindValue(':file',$fileRel);
    $ok = $st->execute();
    json_ok(['success'=>(bool)$ok,'deleted'=>$st->rowCount()]);
}

if ($action === 'bulk_delete_migx_by_file') {
    $tvName = getStr($_REQUEST,'tv_name',$tvDefault);
    $fileRel = trim(getStr($_REQUEST,'file',''),'/');
    if ($fileRel==='') json_ok(['success'=>false,'message'=>'Specify file']);

    $tv = $modx->getObject('modTemplateVar', ['name'=>$tvName]);
    if (!$tv) json_ok(['success'=>false,'message'=>"TV '{$tvName}' not found"]);
    $tvId = (int)$tv->get('id');

    // find all resources that contain this file
    $q = $modx->newQuery('modTemplateVarResource');
    $q->select(['contentid','value']);
    $q->where(['tmplvarid'=>$tvId,'value:LIKE'=>'%'.$fileRel.'%']);

    $changed = 0;
    $removedTotal = 0;

    if ($q->prepare() && $q->stmt->execute()) {
        while ($row = $q->stmt->fetch(PDO::FETCH_ASSOC)) {
            $rid = (int)$row['contentid'];
            $removed = 0;
            if (migx_remove_by_file($modx, $rid, $tvName, $fileRel, $removed) && $removed > 0) {
                $changed++;
                $removedTotal += $removed;
            }
        }
    }

    json_ok(['success'=>true,'resources_changed'=>$changed,'removed_total'=>$removedTotal]);
}

json_ok(['success'=>false,'message'=>'Unknown action: '.$action]);
