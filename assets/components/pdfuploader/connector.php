<?php
/**
 * pdfuploader connector (legacy working contract, paths from system settings only)
 * - search_all: returns {from_registry:[], from_migx:[]}
 * - file_usage: returns {registry:[], migx:[]}
 * - delete_migx_item: legacy params resource_id + file (+image)
 */ 

if (!defined('MODX_API_MODE')) { define('MODX_API_MODE', true); }

header('Content-Type: application/json; charset=utf-8');

// --- JSON-safe fatal handlers (so 500 isn't "silent") ---
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

// --- Find MODX config.core.php robustly (no hardcoded dirname depth) ---
$dir = __DIR__;
$config = '';
for ($i=0; $i<10; $i++) {
    $try = $dir . '/config.core.php';
    if (is_file($try)) { $config = $try; break; }
    $dir = dirname($dir);
}
if (!$config) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'config.core.php not found (search up to 10 levels)'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

require_once $config;
require_once MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

$modx = new modX();
$modx->initialize('mgr');

// $miniShop2 = $modx->getService(
//     'minishop2',
//     'miniShop2',
//     MODX_CORE_PATH . 'components/minishop2/model/minishop2/'
// );

// if (!$miniShop2) {
//     json_ok([
//         'success' => false,
//         'message' => 'miniShop2 service not loaded',
//         'path' => MODX_CORE_PATH . 'components/minishop2/model/minishop2/'
//     ]);
// }

// ---- Force-load miniShop2 model + xPDO meta (reliable) ----
$msModelPath = MODX_CORE_PATH . 'components/minishop2/model/';

// 1) register package in xPDO (meta map + table names)
$modx->addPackage('minishop2', $msModelPath, $modx->getOption('table_prefix'));

// 2) ensure PHP class file is loaded (only once; no metadata override)
if (!class_exists('msVendor', false)) {
    $cls = $msModelPath . 'minishop2/msvendor.class.php';
    if (!is_file($cls)) {
        json_ok(['success'=>false,'message'=>'msvendor.class.php not found', 'path'=>$cls]);
    }
    require_once $cls;
}

// final assert
if (!class_exists('msVendor', false)) {
    json_ok(['success'=>false,'message'=>'msVendor class still not loaded']);
}


// --- Helpers ---
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
 * Normalize MIGX file/image into "folder/name.ext" (relative to base_url)
 * Accepts: full URL, "/assets/..", "folder/name.pdf", "name.pdf" (fallback -> default folder)
 */
function normalizeRel(string $value, string $defaultFolder): array {
    $v = stripSite($value);
    $v = trim($v,'/');
    if ($v === '') return ['folder'=>'', 'name'=>'', 'rel'=>''];

    // If it's "something/something.ext"
    if (strpos($v,'/') !== false) {
        $parts = explode('/',$v);
        $name = array_pop($parts);
        $folder = normFolder(implode('/',$parts));
        return ['folder'=>$folder, 'name'=>$name, 'rel'=>($folder !== '' ? $folder.'/' : '').$name];
    }

    // Only filename
    $name = $v;
    $folder = normFolder($defaultFolder);
    return ['folder'=>$folder, 'name'=>$name, 'rel'=>($folder !== '' ? $folder.'/' : '').$name];
}

/**
 * Read MIGX items from TV (raw JSON array)
 */
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

function ms2_vendor_name(modX $modx, int $vendorId): string {
    if ($vendorId <= 0) return '';
    $pfx = (string)$modx->getOption('table_prefix', null, '');
    $tv  = $pfx . 'ms2_vendors';
    $st = $modx->prepare("SELECT name FROM `{$tv}` WHERE id=:id LIMIT 1");
    if (!$st) return '';
    $st->bindValue(':id', $vendorId);
    if (!$st->execute()) return '';
    return (string)$st->fetchColumn();
}


// --- Options / Settings (NO hardcoded paths) ---
$registryTable = (string)$modx->getOption('pdfuploader.registry_table', null, 'pdfuploader_registry');
$defaultTvName = (string)$modx->getOption('pdfuploader.tv_name', null, 'sertif');
$defaultFolder = (string)$modx->getOption('pdfuploader.default_folder', null, 'manuals');

$docsBasePath   = rtrim((string)$modx->getOption('pdfuploader.docs_base_path', null, ''), "/\\");
$docsBaseUrl    = trim((string)$modx->getOption('pdfuploader.docs_base_url',  null, ''), "/");

$thumbsBasePath = rtrim((string)$modx->getOption('pdfuploader.thumbs_base_path', null, ''), "/\\");
$thumbsBaseUrl  = trim((string)$modx->getOption('pdfuploader.thumbs_base_url',  null, ''), "/");

// Hard requirement: settings must exist
if ($docsBasePath === '' || $docsBaseUrl === '' || $thumbsBasePath === '' || $thumbsBaseUrl === '') {
    json_ok([
        'success'=>false,
        'message'=>'Paths are not configured in system settings (pdfuploader.docs_base_path/docs_base_url/thumbs_base_path/thumbs_base_url)',
        'paths'=>[
            'docs_base_path'=>$docsBasePath,
            'docs_base_url'=>$docsBaseUrl,
            'thumbs_base_path'=>$thumbsBasePath,
            'thumbs_base_url'=>$thumbsBaseUrl,
        ]
    ]);
}

// --- miniShop2 (vendors/products) ---
$modx->addPackage(
    'minishop2',
    MODX_CORE_PATH.'components/minishop2/model/',
    $modx->getOption('table_prefix')
);

$action = getStr($_REQUEST,'action','');

// ping (diagnostic)
if ($action === 'ping') {
    json_ok([
        'success'=>true,
        'paths'=>[
            'docs_base_path'=>$docsBasePath,
            'docs_base_url'=>$docsBaseUrl,
            'thumbs_base_path'=>$thumbsBasePath,
            'thumbs_base_url'=>$thumbsBaseUrl,
        ],
        'tv_default'=>$defaultTvName,
        'default_folder'=>$defaultFolder
    ]);
}

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

// if ($action === 'lookup_product') {
//     $rid = getInt($_REQUEST,'rid',0);
//     $article = getStr($_REQUEST,'article','');
//     $vendorFilter = getInt($_REQUEST,'vendor_id',0);

//     // by id
//     if ($rid > 0) {
//         /** @var msProduct $p */
//         $p = $modx->getObject('msProduct', $rid);
//         if (!$p) json_ok(['success'=>true,'items'=>[]]);

//         $vendorId = (int)$p->get('vendor');
//         // $vendorName = '';
//         // if ($vendorId > 0) {
//         //     $v = $modx->getObject('msVendor', $vendorId);
//         //     $vendorName = $v ? (string)$v->get('name') : '';
//         // }
//         $vendorName = ms2_vendor_name($modx, $vendorId);

//         json_ok(['success'=>true,'items'=>[[
//             'id'=>(int)$p->get('id'),
//             'pagetitle'=>(string)$p->get('pagetitle'),
//             'article'=>(string)$p->get('article'),
//             'vendor_id'=>$vendorId,
//             'vendor_name'=>$vendorName,
//         ]]]);
//     }

//     // by article (+ optional vendor filter)
//     if ($article !== '') {
//         $q = $modx->newQuery('msProduct');
//         $q->select(['id','pagetitle','article','vendor']);
//         $w = ['article' => $article];
//         if ($vendorFilter > 0) $w['vendor'] = $vendorFilter;
//         $q->where($w);
//         $q->limit(50);

//         $items = [];
//         if ($q->prepare() && $q->stmt->execute()) {
//             while ($r = $q->stmt->fetch(PDO::FETCH_ASSOC)) {
//                 $vendorId = (int)$r['vendor'];
//                 $vendorName = '';
//                 if ($vendorId > 0) {
//                     $v = $modx->getObject('msVendor', $vendorId);
//                     $vendorName = $v ? (string)$v->get('name') : '';
//                 }
//                 $items[] = [
//                     'id'=>(int)$r['id'],
//                     'pagetitle'=>(string)$r['pagetitle'],
//                     'article'=>(string)$r['article'],
//                     'vendor_id'=>$vendorId,
//                     'vendor_name'=>$vendorName,
//                 ];
//             }
//         }

//         json_ok(['success'=>true,'items'=>$items]);
//     }

//     json_ok(['success'=>false,'message'=>'Specify rid or article','items'=>[]]);
// }

if ($action === 'lookup_product') {
    $rid = getInt($_REQUEST,'rid',0);
    $article = getStr($_REQUEST,'article','');
    $vendorFilter = getInt($_REQUEST,'vendor_id',0);

    $pfx = (string)$modx->getOption('table_prefix', null, '');
    $tp  = $pfx . 'ms2_products';
    $tv  = $pfx . 'ms2_vendors';

    // by id
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

    // by article (+ optional vendor)
    if ($article !== '') {
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

    json_ok(['success'=>false,'message'=>'Specify rid or article','items'=>[]]);
}


/**
 * search_all (legacy): returns docs from registry + MIGX for given product
 * Input: tv_name, resource_id, article, vendor_id
 * Output: from_registry, from_migx
 */
if ($action === 'search_all') {
    $tvName = getStr($_REQUEST,'tv_name',$defaultTvName);
    $resourceId = getInt($_REQUEST,'resource_id',0);
    $article = getStr($_REQUEST,'article','');
    $vendorId = getInt($_REQUEST,'vendor_id',0);

    if ($resourceId <= 0 && $article !== '') {
        $pfx = (string)$modx->getOption('table_prefix', null, '');
        $tp  = $pfx . 'ms2_products';

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

    // If article/vendor not provided, try take from msProductData
    if ($article === '' || $vendorId <= 0) {
        $d = $modx->getObject('msProductData', $resourceId);
        if ($d) {
            if ($article === '') $article = (string)$d->get('article');
            if ($vendorId <= 0) $vendorId = (int)$d->get('vendor');
        }
    }

    // vendor name
    // $vendorName = '';
    // if ($vendorId > 0) {
    //     $v = $modx->getObject('msVendor', $vendorId);
    //     $vendorName = $v ? (string)$v->get('name') : '';
    // }
    $vendorName = ms2_vendor_name($modx, $vendorId);


    // --- Registry ---
    $fromRegistry = [];
    $diag = [];

    $where = [];
    $params = [];

    if ($article !== '') { $where[] = "article=:a"; $params[':a'] = $article; }
    if ($vendorId > 0)   { $where[] = "vendor_id=:v"; $params[':v'] = $vendorId; }

    if ($where) {
        $sql = "SELECT id,article,vendor_id,vendor_name,folder,pdf_name,thumb_name,pdf_url,thumb_url,createdon
                FROM `{$registryTable}`
                WHERE ".implode(' AND ',$where)."
                ORDER BY createdon DESC";
        $st = $modx->prepare($sql);
        if ($st) {
            foreach ($params as $k=>$v) $st->bindValue($k,$v);
            if ($st->execute()) {
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                    $folder = normFolder((string)($r['folder'] ?? $defaultFolder));
                    $pdfName = (string)($r['pdf_name'] ?? '');
                    if ($pdfName === '') {
                        $pdfName = basename((string)($r['pdf_url'] ?? ''));
                    }
                    $thumbName = (string)($r['thumb_name'] ?? '');
                    if ($thumbName === '') {
                        $thumbName = basename((string)($r['thumb_url'] ?? ''));
                    }

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
                $diag[] = 'registry: execute failed';
            }
        } else {
            $diag[] = 'registry: prepare failed';
        }
    } else {
        $diag[] = 'registry: no filters (article/vendor missing)';
    }

    // --- MIGX ---
    $fromMigx = [];
    $items = migx_get_items($modx, $resourceId, $tvName);

    foreach ($items as $it) {
        $rawFile  = (string)($it['file'] ?? '');
        $rawImage = (string)($it['image'] ?? '');

        $nf = normalizeRel($rawFile, $defaultFolder);
        if ($nf['name'] === '') continue;

        // Image may be missing or in different folder; we still resolve with thumbs base
        $ni = $rawImage !== '' ? normalizeRel($rawImage, $nf['folder'] ?: $defaultFolder) : ['folder'=>'','name'=>'','rel'=>''];

        $folder = $nf['folder'] !== '' ? $nf['folder'] : normFolder($defaultFolder);

        $fromMigx[] = [
            'resource_id'=>$resourceId,
            'article'=>$article,
            'vendor_id'=>$vendorId,
            'vendor_name'=>$vendorName,
            'folder'=>$folder,
            'file'=>$nf['rel'],      // legacy
            'image'=>$ni['rel'],     // legacy
            'pdf_url'=> baseJoinUrl($modx, $docsBaseUrl, $folder, $nf['name']),
            'thumb_url'=> ($ni['name'] !== '' ? baseJoinUrl($modx, $thumbsBaseUrl, ($ni['folder'] ?: $folder), $ni['name']) : ''),
        ];
    }

    json_ok(['success'=>true,'from_registry'=>$fromRegistry,'from_migx'=>$fromMigx,'diagnostics'=>$diag]);
}

/**
 * file_usage (legacy): check where folder/name used
 * Output keys exactly: registry, migx
 */
if ($action === 'file_usage') {
    $tvName = getStr($_REQUEST,'tv_name',$defaultTvName);
    $folder = normFolder(getStr($_REQUEST,'folder',''));
    $name   = trim(getStr($_REQUEST,'name',''));

    if ($folder === '' || $name === '') {
        json_ok(['success'=>false,'message'=>'Specify folder and name']);
    }

    $relative = ($folder !== '' ? $folder.'/' : '') . $name;

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
    if (!$tv) {
        json_ok(['success'=>true,'registry'=>$registry,'migx'=>[],'diagnostics'=>["tv '{$tvName}' not found"]]);
    }
    $tvId = (int)$tv->get('id');

    $q = $modx->newQuery('modTemplateVarResource');
    $q->select(['contentid','value']);
    $q->where([
        'tmplvarid' => $tvId,
        'value:LIKE' => '%' . $relative . '%'
    ]);

    $hits = [];
    if ($q->prepare() && $q->stmt->execute()) {
        while ($row = $q->stmt->fetch(PDO::FETCH_ASSOC)) {
            $rid = (int)$row['contentid'];
            $val = (string)$row['value'];
            $items = json_decode($val,true);
            if (!is_array($items)) continue;

            foreach ($items as $it) {
                $nf = normalizeRel((string)($it['file'] ?? ''), $defaultFolder);
                if ($nf['rel'] === $relative) {
                    $hits[$rid] = true;
                    break;
                }
            }
        }
    }

    if ($hits) {
        $ids = array_keys($hits);

        // titles
        $titles = [];
        $c = $modx->newQuery('modResource');
        $c->select(['id','pagetitle']);
        $c->where(['id:IN'=>$ids]);
        if ($c->prepare() && $c->stmt->execute()) {
            while ($r = $c->stmt->fetch(PDO::FETCH_ASSOC)) $titles[(int)$r['id']] = (string)$r['pagetitle'];
        }

        // product data
        $articles = [];
        $vendorIds = [];
        $d = $modx->newQuery('msProductData');
        $d->select(['id','article','vendor']);
        $d->where(['id:IN'=>$ids]);
        if ($d->prepare() && $d->stmt->execute()) {
            while ($r = $d->stmt->fetch(PDO::FETCH_ASSOC)) {
                $rid = (int)$r['id'];
                $articles[$rid] = (string)$r['article'];
                $vendorIds[$rid] = (int)$r['vendor'];
            }
        }

        // vendor names
        $vendorNames = [];
        $vIds = array_values(array_unique(array_filter($vendorIds)));
        if ($vIds) {
            $v = $modx->newQuery('msVendor');
            $v->select(['id','name']);
            $v->where(['id:IN'=>$vIds]);
            if ($v->prepare() && $v->stmt->execute()) {
                while ($r = $v->stmt->fetch(PDO::FETCH_ASSOC)) $vendorNames[(int)$r['id']] = (string)$r['name'];
            }
        }

        foreach ($ids as $rid) {
            $vid = $vendorIds[$rid] ?? 0;
            $migx[] = [
                'resource_id'=>$rid,
                'pagetitle'=>$titles[$rid] ?? '',
                'article'=>$articles[$rid] ?? '',
                'vendor_id'=>$vid,
                'vendor_name'=>($vid ? ($vendorNames[$vid] ?? '') : ''),
                'folder'=>$folder,
                'file'=>$relative,
                'pdf_url'=> baseJoinUrl($modx, $docsBaseUrl, $folder, $name),
            ];
        }
    }

    json_ok(['success'=>true,'registry'=>$registry,'migx'=>$migx,'file'=>$relative,'count'=>count($migx)+count($registry)]);
}

/**
 * delete_registry: optional file deletion via base_path settings (NO hardcode)
 */
if ($action === 'delete_registry') {
    $id = getInt($_REQUEST,'id',0);
    $deleteFiles = (bool)getInt($_REQUEST,'delete_files',0);
    if ($id <= 0) json_ok(['success'=>false,'message'=>'No id']);

    $st = $modx->prepare("SELECT * FROM `{$registryTable}` WHERE id=:id LIMIT 1");
    $st->bindValue(':id',$id);
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_ok(['success'=>false,'message'=>'Not found']);

    $folder = normFolder((string)($row['folder'] ?? $defaultFolder));
    $pdfName = (string)($row['pdf_name'] ?? '');
    if ($pdfName === '') $pdfName = basename((string)($row['pdf_url'] ?? ''));
    $thumbName = (string)($row['thumb_name'] ?? '');
    if ($thumbName === '') $thumbName = basename((string)($row['thumb_url'] ?? ''));

    $del = $modx->prepare("DELETE FROM `{$registryTable}` WHERE id=:id");
    $del->bindValue(':id',$id);
    $del->execute();

    $files = ['pdf'=>false,'thumb'=>false];
    if ($deleteFiles) {
        $pdfAbs = baseJoinPath($docsBasePath, $folder, $pdfName);
        $thumbAbs = ($thumbName !== '' ? baseJoinPath($thumbsBasePath, $folder, $thumbName) : '');
        if ($pdfAbs !== '' && is_file($pdfAbs)) $files['pdf'] = @unlink($pdfAbs);
        if ($thumbAbs !== '' && is_file($thumbAbs)) $files['thumb'] = @unlink($thumbAbs);
    }

    json_ok(['success'=>true,'deleted'=>true,'files'=>$files]);
}

/**
 * delete_migx_item (legacy): resource_id + file (+image)
 */
if ($action === 'delete_migx_item') {
    $resourceId = getInt($_REQUEST,'resource_id',0);
    $tvName = getStr($_REQUEST,'tv_name',$defaultTvName);
    $fileIn = getStr($_REQUEST,'file','');
    $imageIn = getStr($_REQUEST,'image','');

    if ($resourceId <= 0 || trim($fileIn) === '') {
        json_ok(['success'=>false,'message'=>'Specify resource_id and file']);
    }

    $tv = $modx->getObject('modTemplateVar', ['name'=>$tvName]);
    if (!$tv) json_ok(['success'=>false,'message'=>"TV '{$tvName}' not found"]);

    $tvId = (int)$tv->get('id');
    $tvr = $modx->getObject('modTemplateVarResource', ['contentid'=>$resourceId,'tmplvarid'=>$tvId]);
    if (!$tvr) json_ok(['success'=>true,'deleted'=>false,'message'=>'TV empty']);

    $val = (string)$tvr->get('value');
    $items = json_decode($val,true);
    if (!is_array($items)) $items = [];

    $targetFile = normalizeRel($fileIn, $defaultFolder)['rel'];
    $targetImg  = ($imageIn !== '' ? normalizeRel($imageIn, $defaultFolder)['rel'] : '');

    $before = count($items);
    $filtered = [];

    foreach ($items as $it) {
        $f = normalizeRel((string)($it['file'] ?? ''), $defaultFolder)['rel'];
        $img = normalizeRel((string)($it['image'] ?? ''), $defaultFolder)['rel'];

        $match = ($f === $targetFile);
        if ($match && $targetImg !== '') $match = ($img === $targetImg);

        if (!$match) $filtered[] = $it;
    }

    $after = count($filtered);
    if ($after === $before) json_ok(['success'=>true,'deleted'=>false,'message'=>'Not found']);

    $tvr->set('value', json_encode($filtered, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $tvr->save();

    json_ok(['success'=>true,'deleted'=>true,'before'=>$before,'after'=>$after]);
}

json_ok(['success'=>false,'message'=>'Unknown action']);
