<?php
/**
 * pdfuploader connector
 * - Paths are taken ONLY from system settings:
 *   pdfuploader.docs_base_path, pdfuploader.docs_base_url,
 *   pdfuploader.thumbs_base_path, pdfuploader.thumbs_base_url
 * - MIGX TV docs read via modTemplateVarResource JSON
 * - Vendors/products via SQL on miniShop2 tables (ms2_vendors/ms2_products)
 *
 * Actions:
 *  - ping
 *  - list_vendors
 *  - lookup_product (by article or rid)
 *  - search_all (by resource_id OR by article + optional vendor_id)
 *  - file_usage
 *  - list_folders
 *  - list_files
 *  - upload_pdf / upload_pdf_mass
 *  - delete_registry / bulk_delete_registry_by_file
 *  - delete_migx_item / bulk_delete_migx_by_file
 */

if (!defined('MODX_API_MODE')) define('MODX_API_MODE', true);

header('Content-Type: application/json; charset=utf-8');

// ---------- JSON-safe error handlers (avoid "silent 500") ----------
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(function($e){
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
});
set_error_handler(function($severity,$message,$file,$line){
    if (!(error_reporting() & $severity)) return false;
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>"$message at $file:$line"], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
});

// ---------- Bootstrap MODX (robust config.core.php search) ----------
$dir = __DIR__;
$config = '';
for ($i=0; $i<10; $i++) {
    $try = $dir . '/config.core.php';
    if (is_file($try)) { $config = $try; break; }
    $dir = dirname($dir);
}
if (!$config) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'config.core.php not found'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

require_once $config;
require_once MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

$modx = new modX();
$modx->initialize('mgr');

// ---------- Helpers ----------
function json_ok(array $a){ echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function getStr(array $src, string $k, string $def=''): string { return isset($src[$k]) ? trim((string)$src[$k]) : $def; }
function getInt(array $src, string $k, int $def=0): int { return isset($src[$k]) ? (int)$src[$k] : $def; }

function normFolder(string $s): string {
    $s = trim(str_replace('\\','/',$s));
    $s = preg_replace('~[^\w\-/]+~u', '_', $s);
    return trim($s,'/');
}
function stripSite(string $p): string {
    $p = trim(str_replace('\\','/',$p));
    $p = preg_replace('~^https?://[^/]+/~i', '', $p);
    return ltrim($p,'/');
}
function baseJoinPath(string $basePath, string $folder, string $name): string {
    $basePath = rtrim($basePath, "/\\") . '/';
    $folder = trim($folder,'/');
    return $basePath . ($folder !== '' ? ($folder.'/') : '') . $name;
}
function baseJoinUrl(modX $modx, string $baseUrl, string $folder, string $name): string {
    $site = rtrim((string)$modx->getOption('site_url'), '/');
    $baseUrl = trim($baseUrl,'/').'/';
    $folder = trim($folder,'/');
    $rel = $baseUrl . ($folder !== '' ? ($folder.'/') : '') . $name;
    return $site . '/' . ltrim($rel,'/');
}

/**
 * Normalize file/image path to "folder/name.ext" relative to base_url.
 * Accepts: full URL, "/assets/..", "folder/name.pdf", "name.pdf" (fallback -> default folder)
 */
function normalizeRel(string $value, string $defaultFolder): array {
    $v = stripSite($value);
    $v = trim($v,'/');
    if ($v === '') return ['folder'=>'', 'name'=>'', 'rel'=>''];

    if (strpos($v,'/') !== false) {
        $parts = explode('/',$v);
        $name = array_pop($parts);
        $folder = normFolder(implode('/',$parts));
        return ['folder'=>$folder, 'name'=>$name, 'rel'=>($folder !== '' ? $folder.'/' : '').$name];
    }
    $name = $v;
    $folder = normFolder($defaultFolder);
    return ['folder'=>$folder, 'name'=>$name, 'rel'=>($folder !== '' ? $folder.'/' : '').$name];
}

/** Read MIGX items from TV (raw JSON array) */
function migx_get_items(modX $modx, int $rid, string $tvName): array {
    $tv = $modx->getObject('modTemplateVar', ['name' => $tvName]);
    if (!$tv) return [];
    $tvId = (int)$tv->get('id');

    $tvr = $modx->getObject('modTemplateVarResource', [
        'contentid' => $rid,
        'tmplvarid' => $tvId
    ]);
    if (!$tvr) return [];

    $val = (string)$tvr->get('value');
    if ($val === '') return [];

    $items = json_decode($val, true);
    return is_array($items) ? $items : [];
}

// ---------- Settings (NO hardcoded paths) ----------
$registryTable = (string)$modx->getOption('pdfuploader.registry_table', null, 'pdfuploader_registry');
$tvDefault     = (string)$modx->getOption('pdfuploader.tv_name', null, 'sertif');
$defaultFolder = (string)$modx->getOption('pdfuploader.default_folder', null, 'manuals');

$docsBasePath   = rtrim((string)$modx->getOption('pdfuploader.docs_base_path', null, ''), "/\\");
$docsBaseUrl    = trim((string)$modx->getOption('pdfuploader.docs_base_url',  null, ''), "/");
$thumbsBasePath = rtrim((string)$modx->getOption('pdfuploader.thumbs_base_path', null, ''), "/\\");
$thumbsBaseUrl  = trim((string)$modx->getOption('pdfuploader.thumbs_base_url',  null, ''), "/");

if ($docsBasePath === '' || $docsBaseUrl === '' || $thumbsBasePath === '' || $thumbsBaseUrl === '') {
    json_ok([
        'success'=>false,
        'message'=>'Paths are not configured in system settings',
        'paths'=>[
            'docs_base_path'=>$docsBasePath,
            'docs_base_url'=>$docsBaseUrl,
            'thumbs_base_path'=>$thumbsBasePath,
            'thumbs_base_url'=>$thumbsBaseUrl,
        ]
    ]);
}

$action = getStr($_REQUEST,'action','');

// ---------- Actions ----------
if ($action === 'ping') {
    json_ok([
        'success'=>true,
        'paths'=>[
            'docs_base_path'=>$docsBasePath,
            'docs_base_url'=>$docsBaseUrl,
            'thumbs_base_path'=>$thumbsBasePath,
            'thumbs_base_url'=>$thumbsBaseUrl,
        ],
        'tv_default'=>$tvDefault,
        'default_folder'=>$defaultFolder
    ]);
}

/** Vendors: SQL (stable) */
if ($action === 'list_vendors') {
    $pfx = (string)$modx->getOption('table_prefix', null, '');
    $tv  = $pfx . 'ms2_vendors';

    $st = $modx->prepare("SELECT id, name FROM `{$tv}` ORDER BY name ASC");
    if (!$st || !$st->execute()) {
        json_ok(['success'=>false,'message'=>'vendors sql failed','errorInfo'=>$st ? $st->errorInfo() : null]);
    }

    $items = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $items[] = ['id'=>(int)$r['id'], 'name'=>(string)$r['name']];
    }
    json_ok(['success'=>true,'items'=>$items,'count'=>count($items)]);
}

/** Lookup product by rid or by article (+ optional vendor_id) */
if ($action === 'lookup_product') {
    $rid = getInt($_REQUEST,'rid',0);
    $article = getStr($_REQUEST,'article','');
    $vendorFilter = getInt($_REQUEST,'vendor_id',0);

    $pfx = (string)$modx->getOption('table_prefix', null, '');
    $tp  = $pfx . 'ms2_products';
    $tv  = $pfx . 'ms2_vendors';

    if ($rid > 0) {
        $sql = "SELECT p.id, p.pagetitle, p.article, p.vendor AS vendor_id, v.name AS vendor_name
                FROM `{$tp}` p
                LEFT JOIN `{$tv}` v ON v.id = p.vendor
                WHERE p.id = :id
                LIMIT 1";
        $st = $modx->prepare($sql);
        $st->bindValue(':id', $rid, PDO::PARAM_INT);
        if (!$st || !$st->execute()) {
            json_ok(['success'=>false,'message'=>'lookup by id failed','errorInfo'=>$st ? $st->errorInfo() : null]);
        }
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $items = [];
        if ($r) {
            $items[] = [
                'id' => (int)$r['id'],
                'pagetitle' => (string)$r['pagetitle'],
                'article' => (string)$r['article'],
                'vendor_id' => (int)$r['vendor_id'],
                'vendor_name' => (string)($r['vendor_name'] ?? ''),
            ];
        }
        json_ok(['success'=>true,'items'=>$items]);
    }

    if ($article === '') {
        json_ok(['success'=>false,'message'=>'Specify rid or article','items'=>[]]);
    }

    $sql = "SELECT p.id, p.pagetitle, p.article, p.vendor AS vendor_id, v.name AS vendor_name
            FROM `{$tp}` p
            LEFT JOIN `{$tv}` v ON v.id = p.vendor
            WHERE p.article = :a";
    if ($vendorFilter > 0) $sql .= " AND p.vendor = :vid";
    $sql .= " ORDER BY p.id ASC LIMIT 50";

    $st = $modx->prepare($sql);
    $st->bindValue(':a', $article);
    if ($vendorFilter > 0) $st->bindValue(':vid', $vendorFilter, PDO::PARAM_INT);

    if (!$st || !$st->execute()) {
        json_ok(['success'=>false,'message'=>'lookup by article failed','errorInfo'=>$st ? $st->errorInfo() : null]);
    }

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
    json_ok(['success'=>true,'items'=>$items]);
}

/** Search all: registry + MIGX for resolved product */
if ($action === 'search_all') {
    $tvName   = getStr($_REQUEST,'tv_name', $tvDefault);
    $article  = getStr($_REQUEST,'article','');
    $vendorId = getInt($_REQUEST,'vendor_id',0);
    $ridReq   = (int)($_REQUEST['resource_id'] ?? 0);

    $pfx = (string)$modx->getOption('table_prefix', null, '');
    $tp  = $pfx . 'ms2_products';
    $tv  = $pfx . 'ms2_vendors';

    $resourceId = $ridReq;

    // Resolve by article (+ optional vendor)
    if ($resourceId <= 0 && $article !== '') {
        $sql = "SELECT id FROM `{$tp}` WHERE article = :a";
        if ($vendorId > 0) $sql .= " AND vendor = :vid";
        $sql .= " ORDER BY id ASC LIMIT 1";

        $st = $modx->prepare($sql);
        $st->bindValue(':a', $article);
        if ($vendorId > 0) $st->bindValue(':vid', $vendorId, PDO::PARAM_INT);
        if ($st && $st->execute()) {
            $rid = (int)$st->fetchColumn();
            if ($rid > 0) $resourceId = $rid;
        }
    }

    if ($resourceId <= 0) {
        json_ok(['success'=>true,'from_registry'=>[],'from_migx'=>[],'diagnostics'=>['no_resource_id']]);
    }

    // If article/vendor not provided, fetch from product row
    $vendorName = '';
    $sql = "SELECT p.article, p.vendor AS vendor_id, v.name AS vendor_name
            FROM `{$tp}` p
            LEFT JOIN `{$tv}` v ON v.id = p.vendor
            WHERE p.id = :id LIMIT 1";
    $st = $modx->prepare($sql);
    $st->bindValue(':id', $resourceId, PDO::PARAM_INT);
    if ($st && $st->execute()) {
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($article === '') $article = (string)$row['article'];
            if ($vendorId <= 0) $vendorId = (int)$row['vendor_id'];
            $vendorName = (string)($row['vendor_name'] ?? '');
        }
    }

    // --- Registry (optional) ---
    $fromRegistry = [];
    $diag = [];

    // Registry filters: article + vendor_id if provided
    if ($article !== '') {
        $w = "article = :a";
        $params = [':a'=>$article];
        if ($vendorId > 0) { $w .= " AND vendor_id = :v"; $params[':v'] = $vendorId; }

        $sql = "SELECT id,article,vendor_id,vendor_name,folder,pdf_name,thumb_name,pdf_url,thumb_url,createdon
                FROM `{$registryTable}`
                WHERE {$w}
                ORDER BY createdon DESC";
        $st = $modx->prepare($sql);
        if ($st) {
            foreach ($params as $k=>$v) $st->bindValue($k,$v);
            if ($st->execute()) {
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                    $folder = normFolder((string)($r['folder'] ?? $defaultFolder));
                    $pdfName = (string)($r['pdf_name'] ?? '');
                    if ($pdfName === '') $pdfName = basename((string)($r['pdf_url'] ?? ''));
                    $thumbName = (string)($r['thumb_name'] ?? '');
                    if ($thumbName === '') $thumbName = basename((string)($r['thumb_url'] ?? ''));

                    $fromRegistry[] = [
                        'id'=>(int)$r['id'],
                        'article'=>(string)$r['article'],
                        'vendor_id'=>(int)$r['vendor_id'],
                        'vendor_name'=>(string)($r['vendor_name'] ?: $vendorName),
                        'folder'=>$folder,
                        'pdf_name'=>$pdfName,
                        'thumb_name'=>$thumbName,
                        'pdf_url'=> baseJoinUrl($modx, $docsBaseUrl, $folder, $pdfName),
                        'thumb_url'=> ($thumbName !== '' ? baseJoinUrl($modx, $thumbsBaseUrl, $folder, $thumbName) : ''),
                        'createdon'=>(string)$r['createdon'],
                    ];
                }
            } else {
                $diag[] = 'registry execute failed';
            }
        } else {
            $diag[] = 'registry prepare failed';
        }
    } else {
        $diag[] = 'registry: no article';
    }

    // --- MIGX ---
    $fromMigx = [];
    $items = migx_get_items($modx, $resourceId, $tvName);
    foreach ($items as $it) {
        $rawFile  = (string)($it['file'] ?? '');
        if ($rawFile === '') continue;
        $rawImage = (string)($it['image'] ?? '');

        $nf = normalizeRel($rawFile, $defaultFolder);
        if ($nf['name'] === '') continue;

        $folder = $nf['folder'] !== '' ? $nf['folder'] : normFolder($defaultFolder);
        $ni = $rawImage !== '' ? normalizeRel($rawImage, $folder) : ['folder'=>'','name'=>'','rel'=>''];

        $fromMigx[] = [
            'resource_id'=>$resourceId,
            'article'=>$article,
            'vendor_id'=>$vendorId,
            'vendor_name'=>$vendorName,
            'folder'=>$folder,
            'file'=>$nf['rel'],
            'image'=>$ni['rel'],
            'pdf_url'=> baseJoinUrl($modx, $docsBaseUrl, $folder, $nf['name']),
            'thumb_url'=> ($ni['name'] !== '' ? baseJoinUrl($modx, $thumbsBaseUrl, ($ni['folder'] ?: $folder), $ni['name']) : ''),
        ];
    }

    json_ok(['success'=>true,'from_registry'=>$fromRegistry,'from_migx'=>$fromMigx,'diagnostics'=>$diag]);
}

/** File usage: where a file (folder/name) is referenced */
if ($action === 'file_usage') {
    $tvName = getStr($_REQUEST,'tv_name',$tvDefault);
    $folder = normFolder(getStr($_REQUEST,'folder',''));
    $name   = trim(getStr($_REQUEST,'name',''));

    if ($folder === '' || $name === '') {
        json_ok(['success'=>false,'message'=>'Specify folder and name']);
    }
    $relative = $folder . '/' . $name;

    // registry
    $registry = [];
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
                    'pdf_url'=> baseJoinUrl($modx, $docsBaseUrl, $folder2, $pdfName),
                    'thumb_url'=> ($thumbName !== '' ? baseJoinUrl($modx, $thumbsBaseUrl, $folder2, $thumbName) : ''),
                    'createdon'=>(string)$r['createdon'],
                ];
            }
        }
    }

    // migx usage: LIKE + confirm JSON
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
                $nf = normalizeRel((string)($it['file'] ?? ''), $defaultFolder);
                if ($nf['rel'] === $relative) { $hits[$rid]=true; break; }
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
                    'pdf_url'=> baseJoinUrl($modx, $docsBaseUrl, $folder, $name),
                ];
            }
        }
    }

    json_ok(['success'=>true,'registry'=>$registry,'migx'=>$migx,'file'=>$relative,'count'=>count($migx)+count($registry)]);
}

/** List folders under docs base path */
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

/** List files in folder under docs base path */
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



// ---------- File/preview helpers ----------
function ensureDir(string $dir): bool {
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0775, true) || is_dir($dir);
}

function slugify_filename(string $name, string $forceExt = ''): string {
    $name = trim($name);
    $name = str_replace(['\\','/'], '-', $name);
    $name = preg_replace('~\s+~u', '-', $name);

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $base = pathinfo($name, PATHINFO_FILENAME);

    // translit (safe)
    $base = iconv('UTF-8','ASCII//TRANSLIT//IGNORE', $base);
    $base = $base !== false ? $base : 'file';
    $base = strtolower($base);
    $base = preg_replace('~[^a-z0-9\-_]+~', '-', $base);
    $base = trim(preg_replace('~-+~','-',$base), '-');
    if ($base === '') $base = 'file';

    if ($forceExt !== '') $ext = strtolower($forceExt);
    if ($ext === '') $ext = 'pdf';

    return $base . '.' . $ext;
}

/**
 * Create WEBP preview from first page of PDF (requires Imagick).
 * Returns true on success, false otherwise; diagnostics appended to $diag.
 */
function makeWebpPreview(string $pdfPath, string $thumbPath, array &$diag): bool {
    if (!class_exists('Imagick')) { $diag[]='Imagick not available'; return false; }
    try {
        $im = new Imagick();
        $im->setResolution(160,160);
        $im->readImage($pdfPath . '[0]');
        $im->setImageFormat('webp');
        $im->setImageCompressionQuality(75);
        $im->thumbnailImage(600, 0);
        $ok = $im->writeImage($thumbPath);
        $im->clear();
        $im->destroy();
        return (bool)$ok;
    } catch (Throwable $e) {
        $diag[] = 'preview failed: '.$e->getMessage();
        return false;
    }
}

/** Append MIGX row to TV value */
function migx_append(modX $modx, int $rid, string $tvName, array $row): bool {
    $tv = $modx->getObject('modTemplateVar', ['name'=>$tvName]);
    if (!$tv) return false;
    $current = (string)$tv->getValue($rid);
    $items = $current ? json_decode($current, true) : [];
    if (!is_array($items)) $items = [];
    $items[] = $row;

    $json = json_encode($items, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    /** @var modTemplateVarResource $tvr */
    $tvr = $modx->getObject('modTemplateVarResource', [
        'contentid'=>$rid,
        'tmplvarid'=>(int)$tv->get('id'),
    ]);
    if (!$tvr) {
        $tvr = $modx->newObject('modTemplateVarResource');
        $tvr->set('contentid',$rid);
        $tvr->set('tmplvarid',(int)$tv->get('id'));
    }
    $tvr->set('value', $json);
    return (bool)$tvr->save();
}

/** Remove MIGX rows by file (relative "folder/name.pdf") */
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

// ---------- Upload / Delete actions ----------

if ($action === 'upload_pdf') {
    $folder     = normFolder(getStr($_POST,'folder', $defaultFolder));
    $article    = getStr($_POST,'article','');
    $vendorId   = getInt($_POST,'vendor_id',0);
    $title      = getStr($_POST,'title','');
    $resourceId = getInt($_POST,'resource_id',0);
    $tvName     = getStr($_POST,'tv_name', $tvDefault);

    // Resolve resource_id by article+vendor if needed
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

    $docsDir   = rtrim($docsBasePath,'/\\') . '/' . $folder . '/';
    $thumbsDir = rtrim($thumbsBasePath,'/\\') . '/' . $folder . '/';
    if (!ensureDir($docsDir) || !ensureDir($thumbsDir)) json_ok(['success'=>false,'message'=>'Не удалось создать каталоги']);

    $origName = basename($_FILES['pdf_file']['name'] ?? 'file.pdf');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') json_ok(['success'=>false,'message'=>'Допустим только PDF']);

    $pdfName = slugify_filename($origName, 'pdf');
    $pdfPath = $docsDir . $pdfName;

    // collision -> add timestamp suffix
    if (file_exists($pdfPath)) {
        $base = pathinfo($pdfName, PATHINFO_FILENAME) . '-' . time();
        $pdfName = slugify_filename($base.'.pdf', 'pdf');
        $pdfPath = $docsDir . $pdfName;
    }

    if (!move_uploaded_file($_FILES['pdf_file']['tmp_name'], $pdfPath)) {
        json_ok(['success'=>false,'message'=>'Не удалось сохранить PDF']);
    }

    $thumbName = preg_replace('~\.pdf$~i', '.webp', $pdfName);
    $thumbPath = $thumbsDir . $thumbName;
    $diag = [];
    $thumbOk = makeWebpPreview($pdfPath, $thumbPath, $diag);

    $relativePdf   = ($folder !== '' ? $folder.'/' : '') . $pdfName;
    $relativeThumb = $thumbOk ? (($folder !== '' ? $folder.'/' : '') . $thumbName) : '';

    $pdfUrl   = baseJoinUrl($modx, $docsBaseUrl, $folder, $pdfName);
    $thumbUrl = $thumbOk ? baseJoinUrl($modx, $thumbsBaseUrl, $folder, $thumbName) : '';

    // Vendor name (SQL)
    $vendorName = '';
    if ($vendorId > 0) {
        $pfx = (string)$modx->getOption('table_prefix', null, '');
        $tvn = $pfx . 'ms2_vendors';
        $st = $modx->prepare("SELECT name FROM `{$tvn}` WHERE id=:id LIMIT 1");
        if ($st) { $st->bindValue(':id',$vendorId,PDO::PARAM_INT); if ($st->execute()) $vendorName = (string)$st->fetchColumn(); }
    }

    // Update MIGX if resourceId known
    $migxUpdated = false;
    if ($resourceId > 0) {
        $row = [
            'file'  => $relativePdf,
            'image' => $relativeThumb,
            'title' => $title,
        ];
        $migxUpdated = migx_append($modx, $resourceId, $tvName, $row);
    }

    // Registry insert (best-effort)
    try {
        $st = $modx->prepare("INSERT INTO `{$registryTable}`
            (article,vendor_id,vendor_name,folder,pdf_name,thumb_name,pdf_url,thumb_url,createdon)
            VALUES (:a,:v,:vn,:f,:pn,:tn,:pu,:tu,NOW())");
        if ($st) {
            $st->bindValue(':a',$article);
            $st->bindValue(':v',$vendorId,PDO::PARAM_INT);
            $st->bindValue(':vn',$vendorName);
            $st->bindValue(':f',$folder);
            $st->bindValue(':pn',$pdfName);
            $st->bindValue(':tn',$thumbOk ? $thumbName : '');
            $st->bindValue(':pu',$pdfUrl);
            $st->bindValue(':tu',$thumbUrl);
            $st->execute();
        }
    } catch (Throwable $e) {
        $diag[] = 'registry insert failed: '.$e->getMessage();
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

if ($action === 'upload_pdf_mass') {
    $folder = normFolder(getStr($_POST,'folder', $defaultFolder));
    $tvName = getStr($_POST,'tv_name', $tvDefault);

    if (empty($_FILES['pdf_files'])) json_ok(['success'=>false,'message'=>'Файлы не получены']);

    $docsDir   = rtrim($docsBasePath,'/\\') . '/' . $folder . '/';
    $thumbsDir = rtrim($thumbsBasePath,'/\\') . '/' . $folder . '/';
    if (!ensureDir($docsDir) || !ensureDir($thumbsDir)) json_ok(['success'=>false,'message'=>'Не удалось создать каталоги']);

    $out = [];
    $names = $_FILES['pdf_files']['name'] ?? [];
    $tmps  = $_FILES['pdf_files']['tmp_name'] ?? [];
    $cnt = is_array($names) ? count($names) : 0;

    for ($i=0; $i<$cnt; $i++) {
        $origName = basename($names[$i] ?? 'file.pdf');
        $tmp = $tmps[$i] ?? '';
        if ($tmp === '' || !is_uploaded_file($tmp)) continue;

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') { $out[]=['name'=>$origName,'success'=>false,'message'=>'not pdf']; continue; }

        $pdfName = slugify_filename($origName, 'pdf');
        $pdfPath = $docsDir . $pdfName;
        if (file_exists($pdfPath)) {
            $base = pathinfo($pdfName, PATHINFO_FILENAME) . '-' . time() . '-' . $i;
            $pdfName = slugify_filename($base.'.pdf','pdf');
            $pdfPath = $docsDir . $pdfName;
        }

        if (!move_uploaded_file($tmp, $pdfPath)) {
            $out[]=['name'=>$origName,'success'=>false,'message'=>'move failed'];
            continue;
        }

        $thumbName = preg_replace('~\.pdf$~i', '.webp', $pdfName);
        $thumbPath = $thumbsDir . $thumbName;
        $diag = [];
        $thumbOk = makeWebpPreview($pdfPath, $thumbPath, $diag);

        $out[]=[
            'name'=>$origName,
            'success'=>true,
            'folder'=>$folder,
            'file'=>($folder!==''?$folder.'/':'').$pdfName,
            'image'=>$thumbOk ? (($folder!==''?$folder.'/':'').$thumbName) : '',
            'pdf_url'=>baseJoinUrl($modx, $docsBaseUrl, $folder, $pdfName),
            'thumb_url'=>$thumbOk ? baseJoinUrl($modx, $thumbsBaseUrl, $folder, $thumbName) : '',
            'diagnostics'=>$diag,
        ];
    }

    json_ok(['success'=>true,'folder'=>$folder,'items'=>$out,'count'=>count($out)]);
}

if ($action === 'delete_registry') {
    $id = getInt($_REQUEST,'id',0);
    if ($id<=0) json_ok(['success'=>false,'message'=>'Specify id']);
    $st = $modx->prepare("DELETE FROM `{$registryTable}` WHERE id=:id");
    $st->bindValue(':id',$id,PDO::PARAM_INT);
    $ok = $st->execute();
    json_ok(['success'=>(bool)$ok,'deleted'=>$st->rowCount()]);
}

if ($action === 'bulk_delete_registry_by_file') {
    $folder = normFolder(getStr($_REQUEST,'folder',''));
    $name   = trim(getStr($_REQUEST,'name',''));
    if ($folder==='' || $name==='') json_ok(['success'=>false,'message'=>'Specify folder and name']);
    $st = $modx->prepare("DELETE FROM `{$registryTable}` WHERE folder=:f AND pdf_name=:n");
    $st->bindValue(':f',$folder);
    $st->bindValue(':n',$name);
    $ok = $st->execute();
    json_ok(['success'=>(bool)$ok,'deleted'=>$st->rowCount()]);
}

if ($action === 'delete_migx_item') {
    $rid = getInt($_REQUEST,'resource_id',0);
    $tvName = getStr($_REQUEST,'tv_name',$tvDefault);
    $file = getStr($_REQUEST,'file',''); // expects "folder/name.pdf"
    if ($rid<=0 || $file==='') json_ok(['success'=>false,'message'=>'Specify resource_id and file']);
    $removed = 0;
    $ok = migx_remove_by_file($modx, $rid, $tvName, trim($file,'/'), $removed);
    json_ok(['success'=>(bool)$ok,'removed'=>$removed]);
}

if ($action === 'bulk_delete_migx_by_file') {
    $tvName = getStr($_REQUEST,'tv_name',$tvDefault);
    $file = trim(getStr($_REQUEST,'file',''),'/');
    if ($file==='') json_ok(['success'=>false,'message'=>'Specify file']);

    $tv = $modx->getObject('modTemplateVar', ['name'=>$tvName]);
    if (!$tv) json_ok(['success'=>false,'message'=>"TV '{$tvName}' not found"]);
    $tvId = (int)$tv->get('id');

    $q = $modx->newQuery('modTemplateVarResource');
    $q->select(['contentid','value']);
    $q->where(['tmplvarid'=>$tvId,'value:LIKE'=>'%'.$file.'%']);

    $changed = 0;
    $totalRemoved = 0;
    if ($q->prepare() && $q->stmt->execute()) {
        while ($row = $q->stmt->fetch(PDO::FETCH_ASSOC)) {
            $rid = (int)$row['contentid'];
            $removed = 0;
            if (migx_remove_by_file($modx, $rid, $tvName, $file, $removed) && $removed>0) {
                $changed++;
                $totalRemoved += $removed;
            }
        }
    }

    json_ok(['success'=>true,'resources_changed'=>$changed,'items_removed'=>$totalRemoved,'file'=>$file]);
}

// Fallback
json_ok(['success'=>false,'message'=>'Unknown action']);
