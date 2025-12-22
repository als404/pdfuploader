<?php
declare(strict_types=1);

/**
 * pdfuploader connector (clean + complete)
 * - No hardcoded paths; only system settings
 * - One JSON/error layer
 * - miniShop2: SQL only (no xPDO msVendor)
 * - MIGX TV: read/modify safely
 */

define('MODX_API_MODE', true);

require_once dirname(__DIR__, 3) . '/config.core.php';
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

/** @var modX $modx */
$modx = new modX();
$modx->initialize('mgr');

/* ----------------------------- JSON helpers ----------------------------- */

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

while (ob_get_level()) { @ob_end_clean(); }
if (!$modx->user || !$modx->user->isAuthenticated('mgr')) {
    json_error('Forbidden', [], 403);
}

/* ----------------------------- Settings -------------------------------- */

function opt_trim(modX $modx, string $key): string {
    return trim((string)$modx->getOption($key), " \t\n\r\0\x0B/");
}

$docsBasePath   = opt_trim($modx, 'pdfuploader.docs_base_path');
$docsBaseUrl    = opt_trim($modx, 'pdfuploader.docs_base_url');
$thumbsBasePath = opt_trim($modx, 'pdfuploader.thumbs_base_path');
$thumbsBaseUrl  = opt_trim($modx, 'pdfuploader.thumbs_base_url');

$missing = [];
if ($docsBasePath === '')   $missing[] = 'pdfuploader.docs_base_path';
if ($docsBaseUrl === '')    $missing[] = 'pdfuploader.docs_base_url';
if ($thumbsBasePath === '') $missing[] = 'pdfuploader.thumbs_base_path';
if ($thumbsBaseUrl === '')  $missing[] = 'pdfuploader.thumbs_base_url';
if ($missing) {
    json_error('Missing system settings: ' . implode(', ', $missing));
}

$defaultFolder = (string)$modx->getOption('pdfuploader.default_folder', null, 'manuals');
$tvName        = (string)$modx->getOption('pdfuploader.tv_name', null, 'sertif');
$registryTable = (string)($modx->getOption('table_prefix') ?? '') . (string)$modx->getOption('pdfuploader.registry_table', null, 'pdfuploader_registry');
$useRegistry   = (int)$modx->getOption('pdfuploader.use_registry', null, 1);

function abs_path(modX $modx, string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    if ($path[0] === '/') return rtrim($path, '/') . '/';
    $basePath = rtrim((string)$modx->getOption('base_path'), '/') . '/';
    return rtrim($basePath, '/') . '/' . ltrim($path, '/');
}
$docsBasePath   = abs_path($modx, $docsBasePath);
$thumbsBasePath = abs_path($modx, $thumbsBasePath);

function abs_url(modX $modx, string $u): string {
    $u = trim($u);
    if ($u === '') return '';
    if (preg_match('~^https?://~i', $u)) return rtrim($u, '/');
    $site = rtrim((string)$modx->getOption('site_url'), '/');
    return $site . '/' . ltrim($u, '/');
}
$docsBaseUrl   = abs_url($modx, $docsBaseUrl);
$thumbsBaseUrl = abs_url($modx, $thumbsBaseUrl);

function join_url(string $base, string ...$parts): string {
    $out = rtrim($base, '/');
    foreach ($parts as $p) {
        $p = trim($p, '/');
        if ($p !== '') $out .= '/' . $p;
    }
    return $out;
}

function getStr(array $a, string $k, string $d=''): string {
    return isset($a[$k]) ? (string)$a[$k] : $d;
}

function sanitize_folder(string $s): string {
    $s = trim((string)$s);
    $s = preg_replace('~\s*\(авто\)$~u', '', $s);
    $s = preg_replace('~[^a-z0-9_\-]+~i', '', $s);
    return $s;
}

function ensure_dir(string $path, int $mode = 0775): bool {
    if (is_dir($path)) return true;
    @mkdir($path, $mode, true);
    return is_dir($path);
}

function slugify_filename(string $name, string $forceExt = ''): string {
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $base = pathinfo($name, PATHINFO_FILENAME);

    static $tr = [
        'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Е'=>'E','Ё'=>'E','Ж'=>'Zh','З'=>'Z','И'=>'I','Й'=>'Y','К'=>'K','Л'=>'L',
        'М'=>'M','Н'=>'N','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U','Ф'=>'F','Х'=>'Kh','Ц'=>'Ts','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Sch',
        'Ъ'=>'','Ы'=>'Y','Ь'=>'','Э'=>'E','Ю'=>'Yu','Я'=>'Ya',
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l',
        'м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch',
        'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'
    ];

    $base = strtr($base, $tr);
    $base = preg_replace('~[^A-Za-z0-9_.-]+~', '-', $base);
    $base = preg_replace('~-{2,}~', '-', $base);
    $base = trim($base, '-_.');
    $base = strtolower($base);
    if ($base === '') $base = 'file';

    $finalExt = $forceExt !== '' ? strtolower($forceExt) : $ext;
    if ($finalExt !== '') $base .= '.' . $finalExt;
    return $base;
}

/** Preview JPG generation: Ghostscript -> Imagick fallback */
function makeJpgPreview(string $pdfPath, string $jpgPath, array &$diag = []): bool {
    $W = 270; $H = 382;

    $gs = trim((string)shell_exec('command -v gs 2>/dev/null'));
    if ($gs !== '') {
        $diag[] = 'preview:ghostscript';
        $tmp = preg_replace('~\.jpe?g$~i', '', $jpgPath) . '_tmp.jpg';
        @unlink($tmp);

        $cmd = escapeshellcmd($gs)
            . ' -dSAFER -dBATCH -dNOPAUSE -dFirstPage=1 -dLastPage=1'
            . ' -sDEVICE=jpeg -dJPEGQ=90'
            . " -g{$W}x{$H} -dPDFFitPage"
            . ' -sOutputFile=' . escapeshellarg($tmp)
            . ' ' . escapeshellarg($pdfPath) . ' 2>&1';

        $out = trim((string)shell_exec($cmd));
        if ($out !== '') $diag[] = 'gs_out:' . mb_substr($out, 0, 200);

        if (is_file($tmp) && filesize($tmp) > 0) {
            @unlink($jpgPath);
            @rename($tmp, $jpgPath);
            return is_file($jpgPath) && filesize($jpgPath) > 0;
        }
        $diag[] = 'ghostscript_failed';
    } else {
        $diag[] = 'ghostscript_not_found';
    }

    if (class_exists('Imagick')) {
        try {
            $diag[] = 'preview:imagick';
            $im = new Imagick();
            $im->setResolution(150, 150);
            $im->readImage($pdfPath . '[0]');
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(90);
            $im->thumbnailImage($W, $H, true);

            $im->setImageBackgroundColor('white');
            $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);

            $ok = $im->writeImage($jpgPath);
            $im->clear();
            $im->destroy();

            return $ok && is_file($jpgPath) && filesize($jpgPath) > 0;
        } catch (Throwable $e) {
            $diag[] = 'imagick_error:' . $e->getMessage();
        }
    }

    return false;
}

/* -------------------------- miniShop2 SQL helpers ----------------------- */

function tp(modX $modx): string {
    return (string)$modx->getOption('table_prefix', null, 'modx_');
}
function ms2_table(modX $modx, string $name): string {
    return tp($modx) . 'ms2_' . $name;
}

/**
 * Find resource IDs by article (exact match) from ms2_product_data.article
 * Returns array<int>
 */
function ms2_find_resource_ids_by_article(modX $modx, string $article): array {
    $article = trim($article);
    if ($article === '') return [];

    $tData = ms2_table($modx, 'product_data');
    $sql = "SELECT `id` FROM `{$tData}` WHERE `article` = :a LIMIT 200";
    $stmt = $modx->prepare($sql);
    $stmt->bindValue(':a', $article, PDO::PARAM_STR);
    if (!$stmt->execute()) return [];

    $ids = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ids[] = (int)$row['id'];
    }
    return $ids;
}

/**
 * Get vendor by resource_id: ms2_products.vendor -> ms2_vendors
 */
function ms2_get_vendor_for_resource(modX $modx, int $rid): array {
    $tProd = ms2_table($modx, 'products');
    $tV   = ms2_table($modx, 'vendors');

    $sql = "SELECT v.id AS vendor_id, v.name AS vendor_name
            FROM `{$tProd}` p
            LEFT JOIN `{$tV}` v ON v.id = p.vendor
            WHERE p.id = :id LIMIT 1";
    $stmt = $modx->prepare($sql);
    $stmt->bindValue(':id', $rid, PDO::PARAM_INT);
    if (!$stmt->execute()) return ['vendor_id'=>0,'vendor_name'=>''];

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['vendor_id'=>0,'vendor_name'=>''];
    return ['vendor_id'=>(int)$row['vendor_id'], 'vendor_name'=>(string)$row['vendor_name']];
}

/* -------------------------- MIGX TV helpers ---------------------------- */

function tv_get(modX $modx, string $tvName): ?modTemplateVar {
    return $modx->getObject('modTemplateVar', ['name' => $tvName]);
}

/** returns array of MIGX rows (each row array) */
function migx_get_items(modX $modx, int $resourceId, string $tvName): array {
    $tv = tv_get($modx, $tvName);
    if (!$tv) return [];

    $raw = (string)$tv->getValue($resourceId);
    $raw = trim($raw);
    if ($raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) return [];

    // MIGX sometimes stores object with "fieldValue"/etc; we only handle array-of-rows
    return array_values(array_filter($data, fn($x) => is_array($x)));
}

/** save MIGX rows */
function migx_save_items(modX $modx, int $resourceId, string $tvName, array $rows): bool {
    $tv = tv_get($modx, $tvName);
    if (!$tv) return false;

    // Normalize rows to array of arrays
    $rows = array_values(array_filter($rows, fn($x) => is_array($x)));

    $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) $json = '[]';

    $tv->setValue($resourceId, $json);
    $tv->save();

    // Clear cache for resource
    $modx->cacheManager->refresh([
        'resource' => ['contexts' => ['web','mgr']]
    ]);
    return true;
}

function migx_add_doc(modX $modx, int $resourceId, string $tvName, string $title, string $fileRel, string $imageRel): array {
    $rows = migx_get_items($modx, $resourceId, $tvName);

    // prevent duplicates by file
    foreach ($rows as $r) {
        if (isset($r['file']) && (string)$r['file'] === $fileRel) {
            // update title/image if needed
            $r['name']  = $title;
            $r['image'] = $imageRel;
            // rebuild rows
        }
    }

    $rows[] = [
        'name'  => $title,
        'file'  => $fileRel,
        'image' => $imageRel,
    ];

    migx_save_items($modx, $resourceId, $tvName, $rows);
    return $rows;
}

function migx_remove_doc_by_file(modX $modx, int $resourceId, string $tvName, string $fileRel): bool {
    $rows = migx_get_items($modx, $resourceId, $tvName);
    $before = count($rows);

    $rows = array_values(array_filter($rows, function($r) use ($fileRel) {
        $f = isset($r['file']) ? (string)$r['file'] : '';
        return $f !== $fileRel;
    }));

    if (count($rows) === $before) return false;
    return migx_save_items($modx, $resourceId, $tvName, $rows);
}

/* -------------------------- Registry helpers --------------------------- */

function registry_insert(modX $modx, string $registryTable, array $row): void {
    // row keys: article, vendor_id, vendor_name, folder, pdf_name, thumb_name, pdf_url, thumb_url
    $sql = "INSERT INTO `{$registryTable}`
            (`article`,`vendor_id`,`vendor_name`,`folder`,`pdf_name`,`thumb_name`,`pdf_url`,`thumb_url`,`createdon`,`updatedon`)
            VALUES
            (:article,:vendor_id,:vendor_name,:folder,:pdf_name,:thumb_name,:pdf_url,:thumb_url,NOW(),NOW())";
    $stmt = $modx->prepare($sql);
    $stmt->bindValue(':article', (string)($row['article'] ?? ''), PDO::PARAM_STR);
    $stmt->bindValue(':vendor_id', (int)($row['vendor_id'] ?? 0), PDO::PARAM_INT);
    $stmt->bindValue(':vendor_name', (string)($row['vendor_name'] ?? ''), PDO::PARAM_STR);
    $stmt->bindValue(':folder', (string)($row['folder'] ?? ''), PDO::PARAM_STR);
    $stmt->bindValue(':pdf_name', (string)($row['pdf_name'] ?? ''), PDO::PARAM_STR);
    $stmt->bindValue(':thumb_name', (string)($row['thumb_name'] ?? ''), PDO::PARAM_STR);
    $stmt->bindValue(':pdf_url', (string)($row['pdf_url'] ?? ''), PDO::PARAM_STR);
    $stmt->bindValue(':thumb_url', (string)($row['thumb_url'] ?? ''), PDO::PARAM_STR);
    $stmt->execute();
}

function registry_delete(modX $modx, string $registryTable, string $folder, string $pdfName): int {
    $sql = "DELETE FROM `{$registryTable}` WHERE `folder` = :f AND `pdf_name` = :p";
    $stmt = $modx->prepare($sql);
    $stmt->bindValue(':f', $folder, PDO::PARAM_STR);
    $stmt->bindValue(':p', $pdfName, PDO::PARAM_STR);
    $stmt->execute();
    return (int)$stmt->rowCount();
}

/* ----------------------------- Actions --------------------------------- */

$action = (string)($_REQUEST['action'] ?? '');

if ($action === 'ping') {
    json_ok(['success' => true, 'pong' => true, 'user' => (string)$modx->user->get('username')]);
}

/**
 * list_vendors (SQL)
 */
if ($action === 'list_vendors') {
    $tVendors = ms2_table($modx, 'vendors');
    $sql = "SELECT `id`, `name` FROM `{$tVendors}` ORDER BY `name` ASC";
    $stmt = $modx->prepare($sql);
    if (!$stmt || !$stmt->execute()) {
        json_error('DB error in list_vendors', ['sql'=>$sql,'error'=>$stmt?$stmt->errorInfo():null]);
    }
    $vendors = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $vendors[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
    }
    json_ok(['success' => true, 'vendors' => $vendors]);
}

/**
 * list_files (non-recursive)
 */
if ($action === 'list_files') {
    $folderRaw = getStr($_REQUEST, 'folder', '');
    $folder = sanitize_folder($folderRaw);

    $diag = ['folder_raw='.$folderRaw,'folder_sanitized='.$folder];
    if ($folder === '') json_ok(['success'=>true,'files'=>[],'diagnostics'=>$diag]);

    $dir = rtrim($docsBasePath, '/') . '/' . $folder . '/';
    $diag[] = 'abs_dir='.$dir;

    if (!is_dir($dir)) json_ok(['success'=>true,'files'=>[],'diagnostics'=>array_merge($diag,['is_dir=false'])]);

    $list = @scandir($dir);
    if ($list === false) json_ok(['success'=>true,'files'=>[],'diagnostics'=>array_merge($diag,['scandir=false'])]);

    $files = [];
    foreach ($list as $f) {
        if ($f === '.' || $f === '..') continue;
        if (!preg_match('~\.pdf$~i', $f)) continue;

        $p = $dir . $f;
        $jpg = preg_replace('~\.pdf$~i', '.jpg', $f);

        $files[] = [
            'name'      => $f,
            'size'      => @filesize($p) ?: 0,
            'mtime'     => @filemtime($p) ?: 0,
            'url'       => join_url($docsBaseUrl, $folder, $f),
            'thumb_url' => join_url($thumbsBaseUrl, $folder, $jpg),
        ];
    }

    usort($files, fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
    $diag[] = 'scandir_count='.count($list);

    json_ok(['success'=>true,'files'=>$files,'diagnostics'=>$diag]);
}

/**
 * search_all:
 * - numeric query => resource_id
 * - else => article (exact or LIKE)
 * optional vendor_id filter
 */
if ($action === 'search_all') {
    $query    = trim(getStr($_REQUEST,'query',''));
    $vendorId = (int)($_REQUEST['vendor_id'] ?? 0);

    if ($query === '') json_ok(['success'=>true,'items'=>[]]);

    $tContent = tp($modx) . 'site_content';
    $tProd    = ms2_table($modx, 'products');
    $tData    = ms2_table($modx, 'product_data');
    $tVendors = ms2_table($modx, 'vendors');

    if (ctype_digit($query)) {
        $rid = (int)$query;
        $sql = "SELECT c.id, c.pagetitle,
                       d.article,
                       v.id AS vendor_id, v.name AS vendor_name
                FROM `{$tContent}` c
                LEFT JOIN `{$tProd}` p ON p.id = c.id
                LEFT JOIN `{$tData}` d ON d.id = c.id
                LEFT JOIN `{$tVendors}` v ON v.id = p.vendor
                WHERE c.id = :id
                LIMIT 50";
        $stmt = $modx->prepare($sql);
        $stmt->bindValue(':id', $rid, PDO::PARAM_INT);
        if (!$stmt->execute()) json_error('DB error in search_all (resource_id)', ['sql'=>$sql,'error'=>$stmt->errorInfo()]);
        json_ok(['success'=>true,'items'=>$stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    }

    $like = '%' . $query . '%';
    $sql = "SELECT c.id, c.pagetitle,
                   d.article,
                   v.id AS vendor_id, v.name AS vendor_name
            FROM `{$tContent}` c
            INNER JOIN `{$tProd}` p ON p.id = c.id
            LEFT JOIN `{$tData}` d ON d.id = c.id
            LEFT JOIN `{$tVendors}` v ON v.id = p.vendor
            WHERE (d.article = :exact OR d.article LIKE :like)";
    if ($vendorId > 0) $sql .= " AND p.vendor = :vendor_id ";
    $sql .= " ORDER BY (d.article = :exact) DESC, d.article ASC, c.id DESC
              LIMIT 50";

    $stmt = $modx->prepare($sql);
    $stmt->bindValue(':exact', $query, PDO::PARAM_STR);
    $stmt->bindValue(':like',  $like,  PDO::PARAM_STR);
    if ($vendorId > 0) $stmt->bindValue(':vendor_id', $vendorId, PDO::PARAM_INT);

    if (!$stmt->execute()) json_error('DB error in search_all (article)', ['sql'=>$sql,'error'=>$stmt->errorInfo()]);
    json_ok(['success'=>true,'items'=>$stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

/**
 * upload_pdf (single)
 * params: folder, title (optional), file
 */
if ($action === 'upload_pdf') {
    $folder = sanitize_folder(getStr($_REQUEST,'folder',$defaultFolder));
    if ($folder === '') $folder = sanitize_folder($defaultFolder) ?: 'docs';

    if (empty($_FILES['file']) || !is_array($_FILES['file'])) json_error('No file uploaded');

    $f = $_FILES['file'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) json_error('Upload error', ['code'=>(int)$f['error']]);

    $origName = (string)($f['name'] ?? 'file.pdf');
    $safeName = slugify_filename($origName, 'pdf');

    $pdfDir = rtrim($docsBasePath,'/') . '/' . $folder . '/';
    $jpgDir = rtrim($thumbsBasePath,'/') . '/' . $folder . '/';
    if (!ensure_dir($pdfDir) || !ensure_dir($jpgDir)) json_error('Cannot create target directories', ['pdfDir'=>$pdfDir,'jpgDir'=>$jpgDir]);

    $pdfPath = $pdfDir . $safeName;
    if (!@move_uploaded_file((string)$f['tmp_name'], $pdfPath)) json_error('Failed to save uploaded file');

    $jpgName = preg_replace('~\.pdf$~i', '.jpg', $safeName);
    $jpgPath = $jpgDir . $jpgName;

    $diag = [];
    $okPreview = makeJpgPreview($pdfPath, $jpgPath, $diag);

    json_ok([
        'success' => true,
        'folder'  => $folder,
        'pdf' => ['name'=>$safeName, 'url'=>join_url($docsBaseUrl,$folder,$safeName)],
        'thumb' => ['name'=>$jpgName, 'url'=>join_url($thumbsBaseUrl,$folder,$jpgName), 'generated'=>$okPreview],
        'diagnostics' => $diag,
    ]);
}

/**
 * upload_pdf_mass
 * Upload ONE pdf once, generate jpg once, then attach to many products by articles.
 * params:
 * - folder (optional)
 * - title (optional)
 * - articles (string: one per line / comma / space)
 * - file (upload)
 */
if ($action === 'upload_pdf_mass') {
    $folder = sanitize_folder(getStr($_REQUEST,'folder',$defaultFolder));
    if ($folder === '') $folder = sanitize_folder($defaultFolder) ?: 'docs';

    $title = trim(getStr($_REQUEST,'title',''));
    $articlesRaw = (string)($_REQUEST['articles'] ?? '');

    if (empty($_FILES['file']) || !is_array($_FILES['file'])) json_error('No file uploaded');
    $f = $_FILES['file'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) json_error('Upload error', ['code'=>(int)$f['error']]);

    // Parse articles list
    $articlesRaw = str_replace(["\r\n","\r"], "\n", $articlesRaw);
    $parts = preg_split('~[\n,; \t]+~u', $articlesRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $articles = [];
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p !== '') $articles[] = $p;
    }
    $articles = array_values(array_unique($articles));
    if (!$articles) json_error('No articles provided');

    // Save PDF
    $origName = (string)($f['name'] ?? 'file.pdf');
    $safeName = slugify_filename($origName, 'pdf');

    $pdfDir = rtrim($docsBasePath,'/') . '/' . $folder . '/';
    $jpgDir = rtrim($thumbsBasePath,'/') . '/' . $folder . '/';
    if (!ensure_dir($pdfDir) || !ensure_dir($jpgDir)) json_error('Cannot create target directories', ['pdfDir'=>$pdfDir,'jpgDir'=>$jpgDir]);

    $pdfPath = $pdfDir . $safeName;
    if (!@move_uploaded_file((string)$f['tmp_name'], $pdfPath)) json_error('Failed to save uploaded file');

    // Preview
    $jpgName = preg_replace('~\.pdf$~i', '.jpg', $safeName);
    $jpgPath = $jpgDir . $jpgName;

    $diag = [];
    $okPreview = makeJpgPreview($pdfPath, $jpgPath, $diag);

    // Relative paths for MIGX (store relative to base url paths)
    // We store as "<folder>/<filename>" because file/image TVtype usually expects relative path
    $fileRel  = $folder . '/' . $safeName;
    $imageRel = $folder . '/' . $jpgName;

    if ($title === '') $title = $safeName;

    $report = [
        'attached' => [],
        'not_found_articles' => [],
        'errors' => [],
        'diagnostics' => $diag,
        'pdf' => ['name'=>$safeName, 'url'=>join_url($docsBaseUrl,$folder,$safeName)],
        'thumb' => ['name'=>$jpgName, 'url'=>join_url($thumbsBaseUrl,$folder,$jpgName), 'generated'=>$okPreview],
    ];

    foreach ($articles as $article) {
        $ids = ms2_find_resource_ids_by_article($modx, $article);
        if (!$ids) {
            $report['not_found_articles'][] = $article;
            continue;
        }

        foreach ($ids as $rid) {
            try {
                migx_add_doc($modx, $rid, $tvName, $title, $fileRel, $imageRel);
                $v = ms2_get_vendor_for_resource($modx, $rid);

                if ($useRegistry) {
                    registry_insert($modx, $registryTable, [
                        'article' => $article,
                        'vendor_id' => $v['vendor_id'],
                        'vendor_name' => $v['vendor_name'],
                        'folder' => $folder,
                        'pdf_name' => $safeName,
                        'thumb_name' => $jpgName,
                        'pdf_url' => join_url($docsBaseUrl,$folder,$safeName),
                        'thumb_url' => join_url($thumbsBaseUrl,$folder,$jpgName),
                    ]);
                }

                $report['attached'][] = [
                    'article' => $article,
                    'resource_id' => $rid,
                    'vendor_id' => $v['vendor_id'],
                    'vendor_name' => $v['vendor_name'],
                ];
            } catch (Throwable $e) {
                $report['errors'][] = [
                    'article' => $article,
                    'resource_id' => $rid,
                    'error' => $e->getMessage(),
                ];
            }
        }
    }

    json_ok(['success'=>true,'report'=>$report]);
}

/**
 * file_usage
 * Find all resources where MIGX TV contains fileRel or just filename (safe fallback).
 * params: folder, name (pdf filename)
 */
if ($action === 'file_usage') {
    $folder = sanitize_folder(getStr($_REQUEST,'folder',''));
    $name = trim(getStr($_REQUEST,'name',''));
    if ($folder === '' || $name === '') json_error('Missing folder or name');

    $tv = tv_get($modx, $tvName);
    if (!$tv) json_error('TV not found', ['tv'=>$tvName]);

    $tvId = (int)$tv->get('id');

    $tTvRes = tp($modx) . 'site_tmplvar_contentvalues';
    $tContent = tp($modx) . 'site_content';

    $fileRel = $folder . '/' . $name;

    // search by rel path first; fallback by filename
    $sql = "SELECT tv.contentid AS resource_id, c.pagetitle, tv.value
            FROM `{$tTvRes}` tv
            LEFT JOIN `{$tContent}` c ON c.id = tv.contentid
            WHERE tv.tmplvarid = :tvid
              AND (tv.value LIKE :like1 OR tv.value LIKE :like2)
            LIMIT 1000";
    $stmt = $modx->prepare($sql);
    $stmt->bindValue(':tvid', $tvId, PDO::PARAM_INT);
    $stmt->bindValue(':like1', '%' . $fileRel . '%', PDO::PARAM_STR);
    $stmt->bindValue(':like2', '%' . $name . '%', PDO::PARAM_STR);

    if (!$stmt->execute()) json_error('DB error in file_usage', ['sql'=>$sql,'error'=>$stmt->errorInfo()]);

    $usages = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $usages[] = [
            'resource_id' => (int)$row['resource_id'],
            'pagetitle' => (string)$row['pagetitle'],
        ];
    }

    json_ok(['success'=>true,'usages'=>$usages,'count'=>count($usages),'file'=>$fileRel]);
}

/**
 * delete_migx_item
 * Remove file from ONE resource MIGX
 * params: resource_id, folder, name
 */
if ($action === 'delete_migx_item') {
    $rid = (int)($_REQUEST['resource_id'] ?? 0);
    $folder = sanitize_folder(getStr($_REQUEST,'folder',''));
    $name = trim(getStr($_REQUEST,'name',''));
    if ($rid <= 0 || $folder === '' || $name === '') json_error('Missing params');

    $fileRel = $folder . '/' . $name;
    $removed = migx_remove_doc_by_file($modx, $rid, $tvName, $fileRel);

    json_ok(['success'=>true,'removed'=>$removed,'resource_id'=>$rid,'file'=>$fileRel]);
}

/**
 * delete_all_usages
 * Remove file from ALL resources MIGX where present
 * params: folder, name
 */
if ($action === 'delete_all_usages') {
    $folder = sanitize_folder(getStr($_REQUEST,'folder',''));
    $name = trim(getStr($_REQUEST,'name',''));
    if ($folder === '' || $name === '') json_error('Missing folder or name');

    $tv = tv_get($modx, $tvName);
    if (!$tv) json_error('TV not found', ['tv'=>$tvName]);
    $tvId = (int)$tv->get('id');

    $tTvRes = tp($modx) . 'site_tmplvar_contentvalues';
    $fileRel = $folder . '/' . $name;

    $sql = "SELECT tv.contentid AS resource_id
            FROM `{$tTvRes}` tv
            WHERE tv.tmplvarid = :tvid AND tv.value LIKE :like
            LIMIT 2000";
    $stmt = $modx->prepare($sql);
    $stmt->bindValue(':tvid', $tvId, PDO::PARAM_INT);
    $stmt->bindValue(':like', '%' . $fileRel . '%', PDO::PARAM_STR);

    if (!$stmt->execute()) json_error('DB error in delete_all_usages', ['sql'=>$sql,'error'=>$stmt->errorInfo()]);

    $ids = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $ids[] = (int)$row['resource_id'];
    $ids = array_values(array_unique($ids));

    $removedFrom = [];
    foreach ($ids as $rid) {
        if (migx_remove_doc_by_file($modx, $rid, $tvName, $fileRel)) {
            $removedFrom[] = $rid;
        }
    }

    json_ok(['success'=>true,'file'=>$fileRel,'checked'=>count($ids),'removed_count'=>count($removedFrom),'removed_from'=>$removedFrom]);
}

/**
 * delete_registry
 * Delete registry records by folder+pdf_name
 * params: folder, name
 */
if ($action === 'delete_registry') {
    if (!$useRegistry) json_ok(['success'=>true,'deleted'=>0,'note'=>'registry disabled']);

    $folder = sanitize_folder(getStr($_REQUEST,'folder',''));
    $name = trim(getStr($_REQUEST,'name',''));
    if ($folder === '' || $name === '') json_error('Missing folder or name');

    $deleted = registry_delete($modx, $registryTable, $folder, $name);
    json_ok(['success'=>true,'deleted'=>$deleted,'folder'=>$folder,'name'=>$name]);
}

json_error('Unknown action', ['action' => $action]);
