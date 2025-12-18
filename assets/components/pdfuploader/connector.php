<?php
declare(strict_types=1);

/**
 * pdfuploader connector (clean)
 * - No hardcoded paths; only system settings
 * - One JSON layer / one error handling layer
 * - miniShop2: no xPDO model dependency (vendors + article search via SQL)
 * - MIGX TV operations stay via MODX API
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

/* Always return JSON even on fatal */
set_exception_handler(function(Throwable $e) use ($modx) {
    if ($modx) {
        $modx->log(modX::LOG_LEVEL_ERROR,
            '[pdfuploader] EXCEPTION: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine()
        );
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
        $modx->log(modX::LOG_LEVEL_ERROR,
            '[pdfuploader] FATAL: ' . $err['message'] . ' at ' . $err['file'] . ':' . $err['line']
        );
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

$registryTable = ($modx->getOption('table_prefix') ?? '') .
    (string)$modx->getOption('pdfuploader.registry_table', null, 'pdfuploader_registry');

$useRegistry   = (int)$modx->getOption('pdfuploader.use_registry', null, 1);

function abs_path(string $path, string $basePath): string {
    // If already absolute
    if ($path !== '' && $path[0] === '/') return rtrim($path, '/') . '/';
    // Relative -> base_path + relative
    return rtrim($basePath, '/') . '/' . ltrim($path, '/');
}

$basePath = rtrim((string)$modx->getOption('base_path'), '/') . '/';
$docsBasePath   = abs_path($docsBasePath, $basePath);
$thumbsBasePath = abs_path($thumbsBasePath, $basePath);

// URLs: allow absolute; if relative -> site_url + relative
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

function ms2_table(modX $modx, string $name): string {
    $tp = $modx->getOption('table_prefix') ?: 'modx_';
    // miniShop2 tables are ms2_*
    return $tp . 'ms2_' . $name;
}

/* ----------------------------- Actions --------------------------------- */

$action = (string)($_REQUEST['action'] ?? '');

if ($action === 'ping') {
    json_ok(['success' => true, 'pong' => true, 'user' => (string)$modx->user->get('username')]);
}

/**
 * list_vendors (SQL) - stable, does not depend on msVendor xPDO map
 */
if ($action === 'list_vendors') {
    $tVendors = ms2_table($modx, 'vendors');

    $sql = "SELECT `id`, `name` FROM `{$tVendors}` ORDER BY `name` ASC";
    $stmt = $modx->prepare($sql);

    if (!$stmt || !$stmt->execute()) {
        json_error('DB error in list_vendors', [
            'sql' => $sql,
            'error' => $stmt ? $stmt->errorInfo() : null,
        ]);
    }

    $vendors = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $vendors[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
    }

    json_ok(['success' => true, 'vendors' => $vendors]);
}

/**
 * list_files: list PDFs in folder (non-recursive)
 */
if ($action === 'list_files') {
    $folderRaw = (string)($_REQUEST['folder'] ?? '');
    $folder    = sanitize_folder($folderRaw);

    $diag = [
        'folder_raw=' . $folderRaw,
        'folder_sanitized=' . $folder,
    ];

    if ($folder === '') {
        json_ok(['success' => true, 'files' => [], 'diagnostics' => $diag]);
    }

    $dir = rtrim($docsBasePath, '/') . '/' . $folder . '/';
    $diag[] = 'abs_dir=' . $dir;

    if (!is_dir($dir)) {
        $diag[] = 'is_dir=false';
        json_ok(['success' => true, 'files' => [], 'diagnostics' => $diag]);
    }
    $diag[] = 'is_dir=true';

    $list = @scandir($dir);
    if ($list === false) {
        $diag[] = 'scandir=false';
        json_ok(['success' => true, 'files' => [], 'diagnostics' => $diag]);
    }
    $diag[] = 'scandir_count=' . count($list);

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

    usort($files, fn($a,$b) => strnatcasecmp($a['name'], $b['name']));
    json_ok(['success' => true, 'files' => $files, 'diagnostics' => $diag]);
}

/**
 * search_all:
 * - if numeric -> resource_id search
 * - else -> article search (ms2_product_data.article)
 * Optional filters:
 * - vendor_id (int)
 */
if ($action === 'search_all') {
    $query    = trim((string)($_REQUEST['query'] ?? ''));
    $vendorId = (int)($_REQUEST['vendor_id'] ?? 0);

    if ($query === '') {
        json_ok(['success' => true, 'items' => []]);
    }

    $tp = $modx->getOption('table_prefix') ?: 'modx_';
    $tContent = $tp . 'site_content';
    $tProd    = ms2_table($modx, 'products');
    $tData    = ms2_table($modx, 'product_data');
    $tVendors = ms2_table($modx, 'vendors');

    // resource_id
    if (ctype_digit($query)) {
        $rid = (int)$query;

        $sql = "
            SELECT c.id, c.pagetitle,
                   d.article,
                   v.id AS vendor_id, v.name AS vendor_name
            FROM `{$tContent}` c
            LEFT JOIN `{$tProd}` p ON p.id = c.id
            LEFT JOIN `{$tData}` d ON d.id = c.id
            LEFT JOIN `{$tVendors}` v ON v.id = p.vendor
            WHERE c.id = :id
            LIMIT 50
        ";

        $stmt = $modx->prepare($sql);
        $stmt->bindValue(':id', $rid, PDO::PARAM_INT);

        if (!$stmt->execute()) {
            json_error('DB error in search_all (resource_id)', [
                'sql' => $sql,
                'error' => $stmt->errorInfo(),
            ]);
        }

        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        json_ok(['success' => true, 'items' => $items]);
    }

    // article
    $like = '%' . $query . '%';

    $sql = "
        SELECT c.id, c.pagetitle,
               d.article,
               v.id AS vendor_id, v.name AS vendor_name
        FROM `{$tContent}` c
        INNER JOIN `{$tProd}` p ON p.id = c.id
        LEFT JOIN `{$tData}` d ON d.id = c.id
        LEFT JOIN `{$tVendors}` v ON v.id = p.vendor
        WHERE (d.article = :exact OR d.article LIKE :like)
    ";

    if ($vendorId > 0) {
        $sql .= " AND p.vendor = :vendor_id ";
    }

    $sql .= "
        ORDER BY (d.article = :exact) DESC, d.article ASC, c.id DESC
        LIMIT 50
    ";

    $stmt = $modx->prepare($sql);
    $stmt->bindValue(':exact', $query, PDO::PARAM_STR);
    $stmt->bindValue(':like',  $like,  PDO::PARAM_STR);
    if ($vendorId > 0) {
        $stmt->bindValue(':vendor_id', $vendorId, PDO::PARAM_INT);
    }

    if (!$stmt->execute()) {
        json_error('DB error in search_all (article)', [
            'sql' => $sql,
            'error' => $stmt->errorInfo(),
        ]);
    }

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    json_ok(['success' => true, 'items' => $items]);
}

/**
 * upload_pdf: upload one PDF to folder and generate JPG preview
 * params: folder (string), file (upload)
 */
if ($action === 'upload_pdf') {
    $folder = sanitize_folder((string)($_REQUEST['folder'] ?? $defaultFolder));
    if ($folder === '') $folder = sanitize_folder($defaultFolder);
    if ($folder === '') $folder = 'docs';

    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        json_error('No file uploaded');
    }

    $f = $_FILES['file'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_error('Upload error', ['code' => (int)$f['error']]);
    }

    $origName = (string)($f['name'] ?? 'file.pdf');
    $safeName = slugify_filename($origName, 'pdf');

    $pdfDir   = rtrim($docsBasePath, '/') . '/' . $folder . '/';
    $jpgDir   = rtrim($thumbsBasePath, '/') . '/' . $folder . '/';

    if (!ensure_dir($pdfDir) || !ensure_dir($jpgDir)) {
        json_error('Cannot create target directories', ['pdfDir' => $pdfDir, 'jpgDir' => $jpgDir]);
    }

    $pdfPath = $pdfDir . $safeName;
    if (!@move_uploaded_file((string)$f['tmp_name'], $pdfPath)) {
        json_error('Failed to save uploaded file');
    }

    $jpgName = preg_replace('~\.pdf$~i', '.jpg', $safeName);
    $jpgPath = $jpgDir . $jpgName;

    $diag = [];
    $okPreview = makeJpgPreview($pdfPath, $jpgPath, $diag);

    json_ok([
        'success' => true,
        'folder'  => $folder,
        'pdf' => [
            'name' => $safeName,
            'path' => $pdfPath,
            'url'  => join_url($docsBaseUrl, $folder, $safeName),
        ],
        'thumb' => [
            'name' => $jpgName,
            'path' => $jpgPath,
            'url'  => join_url($thumbsBaseUrl, $folder, $jpgName),
            'generated' => $okPreview,
        ],
        'diagnostics' => $diag,
    ]);
}

/**
 * TODO actions:
 * - upload_pdf_mass
 * - file_usage
 * - delete_migx_item
 * - delete_registry
 * - delete_all_usages
 *
 * Их можно переносить из вашей текущей версии и вставлять ниже,
 * сохранив общий стиль: один набор хелперов, один JSON слой,
 * пути только из системных настроек.
 */

json_error('Unknown action', ['action' => $action]);
