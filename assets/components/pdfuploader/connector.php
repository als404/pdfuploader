<?php
declare(strict_types=1);

/* ===== BOOTSTRAP MODX ===== */
define('MODX_API_MODE', true);

require_once dirname(__DIR__, 3) . '/config.core.php';
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';

/** @var modX $modx */
$modx = new modX();
$modx->initialize('mgr');

// --- JSON helpers MUST be defined before any usage ---
if (!function_exists('json_ok')) {
    function json_ok(array $data = []): void {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('json_error')) {
    function json_error(string $message, array $data = []): void {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge([
            'success' => false,
            'message' => $message,
        ], $data), JSON_UNESCAPED_UNICODE);
        exit;
    }
}


// ---------------- miniShop2 xPDO bootstrap ----------------
$ms2CorePath = (string)$modx->getOption(
    'minishop2.core_path',
    null,
    $modx->getOption('core_path') . 'components/minishop2/'
);

$ms2ModelPath = rtrim($ms2CorePath, '/') . '/model/minishop2/';

// 1. Регистрируем модель ЯВНО
$modx->addPackage('minishop2', $ms2ModelPath);

// 2. Поднимаем сервис (не обязательно, но полезно)
$modx->getService('miniShop2', 'miniShop2', $ms2ModelPath);

// 3. Жёсткая проверка
if (!class_exists('msVendor', false)) {
    json_error('miniShop2 model not loaded: msVendor class missing', [
        'minishop2.core_path' => $ms2CorePath,
        'model_path'          => $ms2ModelPath,
    ]);
}
// ----------------------------------------------------------

/** pdfuploader settings (paths, tv, registry table) */
// 1) читаем пути строго из системных настроек (без дефолтов)
$docsBasePath   = trim((string)$modx->getOption('pdfuploader.docs_base_path'),   " \t\n\r\0\x0B/");
$docsBaseUrl    = trim((string)$modx->getOption('pdfuploader.docs_base_url'),    " \t\n\r\0\x0B/");
$thumbsBasePath = trim((string)$modx->getOption('pdfuploader.thumbs_base_path'), " \t\n\r\0\x0B/");
$thumbsBaseUrl  = trim((string)$modx->getOption('pdfuploader.thumbs_base_url'),  " \t\n\r\0\x0B/");

// 2) проверяем обязательные настройки
$missing = [];
if ($docsBasePath === '')    $missing[] = 'pdfuploader.docs_base_path';
if ($docsBaseUrl === '')     $missing[] = 'pdfuploader.docs_base_url';
if ($thumbsBasePath === '')  $missing[] = 'pdfuploader.thumbs_base_path';
if ($thumbsBaseUrl === '')   $missing[] = 'pdfuploader.thumbs_base_url';

if (!empty($missing)) {
    json_error('Missing system settings: ' . implode(', ', $missing));
}

// 3) нормализуем до удобного формата (в конце /)
$docsBasePath   = rtrim($docsBasePath, '/') . '/';
$docsBaseUrl    = rtrim($docsBaseUrl, '/') . '/';
$thumbsBasePath = rtrim($thumbsBasePath, '/') . '/';
$thumbsBaseUrl  = rtrim($thumbsBaseUrl, '/') . '/';

// 4) остальное можно оставить с дефолтами (это не пути)
$defaultFolder = (string)$modx->getOption('pdfuploader.default_folder', null, 'manuals');
$tvName        = (string)$modx->getOption('pdfuploader.tv_name', null, 'sertif');
$registryTable = ($modx->getOption('table_prefix') ?? '') . (string)$modx->getOption('pdfuploader.registry_table', null, 'pdfuploader_registry');
$useRegistry   = (int)$modx->getOption('pdfuploader.use_registry', null, 1);

// URL helpers (put above build functions)
if (!function_exists('pdfu_joinUrl')) {
    function pdfu_joinUrl(string $base, string ...$parts): string {
        $base = rtrim($base, '/');
        $out = $base;
        foreach ($parts as $p) {
            $p = trim($p, '/');
            if ($p !== '') {
                $out .= '/' . $p;
            }
        }
        return $out;
    }
}

if (!function_exists('pdfu_baseUrl')) {
    function pdfu_baseUrl(modX $modx, string $key): string {
        $v = trim((string)$modx->getOption($key));
        $v = trim($v, " \t\n\r\0\x0B/");

        if ($v === '') {
            // no defaults allowed; caller may decide how to error
            return '';
        }

        // If already absolute, return as-is (normalized)
        if (preg_match('~^https?://~i', $v)) {
            return rtrim($v, '/');
        }

        // Relative base: prefix with site_url
        $siteUrl = rtrim((string)$modx->getOption('site_url'), '/');
        return $siteUrl . '/' . ltrim($v, '/');
    }
}

if (!function_exists('pdfu_buildDocUrl')) {
    function pdfu_buildDocUrl(modX $modx, string $folder, string $name): string {
        $base = pdfu_baseUrl($modx, 'pdfuploader.docs_base_url');
        if ($base === '') {
            return ''; // или бросайте исключение/делайте json_error выше по коду
        }
        return pdfu_joinUrl($base, $folder, $name);
    }
}

if (!function_exists('pdfu_buildThumbUrl')) {
    function pdfu_buildThumbUrl(modX $modx, string $folder, string $name): string {
        $base = pdfu_baseUrl($modx, 'pdfuploader.thumbs_base_url');
        if ($base === '') {
            return '';
        }
        return pdfu_joinUrl($base, $folder, $name);
    }
}


header('Content-Type: application/json; charset=utf-8');

// ---- Fatal/Exception guard: always return JSON instead of 500 white-screen
if (!function_exists('pdfu_fail')) {
    function pdfu_fail(string $message, array $data = []): void {
        http_response_code(200);
        echo json_encode(array_merge(['success' => false, 'message' => $message], $data), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

set_exception_handler(function($e) use ($modx) {
    if ($modx) $modx->log(modX::LOG_LEVEL_ERROR, '[pdfuploader] EXCEPTION: '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());
    pdfu_fail('Server error (exception). See MODX error log.', [
        'error' => $e->getMessage(),
        'file'  => basename($e->getFile()),
        'line'  => $e->getLine(),
    ]);
});

register_shutdown_function(function() use ($modx) {
    $err = error_get_last();
    if (!$err) return;
    if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;

    if ($modx) $modx->log(modX::LOG_LEVEL_ERROR, '[pdfuploader] FATAL: '.$err['message'].' at '.$err['file'].':'.$err['line']);
    // JSON even on fatal
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'message' => 'Server error (fatal). See MODX error log.',
        'error'   => $err['message'],
        'file'    => basename((string)$err['file']),
        'line'    => (int)$err['line'],
    ], JSON_UNESCAPED_UNICODE);
});


// чтобы msVendor/msProductData были доступны
// $modx->addPackage('minishop2', MODX_CORE_PATH . 'components/minishop2/model/');
// ---------------- miniShop2 xPDO bootstrap ----------------
$ms2CorePath = (string)$modx->getOption(
    'minishop2.core_path',
    null,
    MODX_CORE_PATH . 'components/minishop2/'
);

$ms2ModelPath = rtrim($ms2CorePath, '/') . '/model/minishop2/';

// 1. Регистрируем пакет в xPDO
$modx->addPackage('minishop2', $ms2ModelPath, '');

// 2. Принудительно подгружаем класс (ВАЖНО)
$modx->loadClass('msVendor', $ms2ModelPath . 'msvendor.class.php', true, true);

// 3. Контроль
if (!class_exists('msVendor', false)) {
    json_error(
        'miniShop2 model not loaded: msVendor class missing',
        [
            'minishop2.core_path' => $ms2CorePath,
            'model_path'          => $ms2ModelPath,
            'files'               => @scandir($ms2ModelPath),
        ]
    );
}
// ----------------------------------------------------------



/* only manager users */
if (!$modx->user || !$modx->user->isAuthenticated('mgr')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* always JSON */
while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');

/* error handlers */
set_exception_handler(function(Throwable $e){
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error','error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
});
set_error_handler(function($no,$str,$file,$line){
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'PHP error','error'=>"$str @ $file:$line"], JSON_UNESCAPED_UNICODE);
    exit;
});

/* miniShop2 models */
// $modx->addPackage('minishop2', MODX_CORE_PATH.'components/minishop2/model/', $modx->config['table_prefix'] ?? '');


/* ===== HELPERS (safe) ===== */
function json_ok(array $a){ echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }

function absUrl(modX $modx, string $p): string {
    if ($p === '' || preg_match('~^https?://~i', $p)) return $p;
    $site = rtrim($modx->getOption('site_url'), '/');
    $p = ltrim($p, '/');
    return $site.'/'.$p;
}
function sanitizeFolder($s): string {
    $s = is_string($s) ? $s : (is_null($s) ? '' : (string)$s);
    $s = trim($s);
    $s = preg_replace('~[^a-z0-9_\-]+~i', '', $s);
    return $s !== '' ? $s : 'default';
}
function ensureDir(string $path, int $mode = 0775): bool {
    if (is_dir($path)) return true;
    if (@mkdir($path, $mode, true)) return true;
    return is_dir($path);
}
function getStr(array $arr, string $key, string $def=''): string {
    if (!array_key_exists($key, $arr) || $arr[$key] === null) return $def;
    return trim((string)$arr[$key]);
}
function getInt(array $arr, string $key, int $def=0): int {
    if (!array_key_exists($key, $arr) || $arr[$key] === null) return $def;
    return (int)$arr[$key];
}
function folderFromPath(string $path, string $base): ?string {
    if ($path === '') return null;
    $u = parse_url($path, PHP_URL_PATH) ?: $path;
    $u = ltrim($u, '/');
    $rx = '~^'.preg_quote($base,'~').'/(.+?)/[^/]+$~i';
    if (preg_match($rx, $u, $m)) return $m[1];
    return null;
}
function legacyFolderGuess(string $path): ?string {
    if ($path === '') return null;
    $u = trim(parse_url($path, PHP_URL_PATH) ?: $path, '/');
    if ($u==='') return null;
    $first = explode('/', $u, 2)[0];
    if ($first && stripos($first,'assets')!==0) return sanitizeFolder($first);
    return null;
}

// --- helpers для приведения путей к относительным (для MIGX) ---
function toRelDoc(string $p): string {
    global $modx;

    $u = parse_url($p, PHP_URL_PATH) ?: $p;
    $u = ltrim($u, '/');

    $base = trim((string)$modx->getOption('pdfuploader.docs_base_url', null, 'assets/files/docs/'), "/") . '/';
    if (stripos($u, $base) === 0) {
        $u = substr($u, strlen($base));
    }
    return ltrim($u, '/');
}
function toRelThumb(string $p): string {
    global $modx;

    $u = parse_url($p, PHP_URL_PATH) ?: $p;
    $u = ltrim($u, '/');

    $base = trim((string)$modx->getOption('pdfuploader.thumbs_base_url', null, 'assets/images/thumbs/'), "/") . '/';
    if (stripos($u, $base) === 0) {
        $u = substr($u, strlen($base));
    }
    return ltrim($u, '/');
}

function makeJpgPreview(string $pdfPath, string $jpgPath, array &$diag = []): bool {
    // Требуемый размер превью
    $W = 270; $H = 382;

    // 1) Пробуем Ghostscript (самый стабильный вариант на серверах)
    $gs = trim((string)shell_exec('command -v gs 2>/dev/null'));
    if ($gs !== '') {
        $diag[] = 'preview: ghostscript';

        // Рендерим первую страницу в JPEG нужного размера.
        // -g задаёт пиксельный холст, -dPDFFitPage вписывает страницу.
        $tmp = preg_replace('~\.jpe?g$~i', '', $jpgPath) . '_tmp.jpg';

        @unlink($tmp);
        $cmd = escapeshellcmd($gs)
            .' -dSAFER -dBATCH -dNOPAUSE -dFirstPage=1 -dLastPage=1'
            .' -sDEVICE=jpeg -dJPEGQ=90'
            ." -g{$W}x{$H} -dPDFFitPage"
            .' -sOutputFile='.escapeshellarg($tmp)
            .' '.escapeshellarg($pdfPath).' 2>&1';

        $out = trim((string)shell_exec($cmd));
        if ($out !== '') $diag[] = 'gs_out: '.mb_substr($out, 0, 300);

        if (is_file($tmp) && filesize($tmp) > 0) {
            // на всякий случай переименуем в нужное имя
            @unlink($jpgPath);
            @rename($tmp, $jpgPath);
            return is_file($jpgPath) && filesize($jpgPath) > 0;
        }

        $diag[] = 'ghostscript_failed';
    } else {
        $diag[] = 'ghostscript not found';
    }

    // 2) Fallback: Imagick (если разрешён PDF и доступен)
    if (class_exists('Imagick')) {
        try {
            $diag[] = 'preview: imagick';
            $im = new Imagick();
            $im->setResolution(150, 150);
            $im->readImage($pdfPath.'[0]');
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(90);
            $im->thumbnailImage($W, $H, true);
            // белый фон + flatten, чтобы не было прозрачности/артефактов
            $im->setImageBackgroundColor('white');
            $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $ok = $im->writeImage($jpgPath);
            $im->clear();
            $im->destroy();
            return $ok && is_file($jpgPath) && filesize($jpgPath) > 0;
        } catch (Throwable $e) {
            $diag[] = 'imagick error: '.$e->getMessage();
        }
    }

    return false;
}


// --- filename slugify (транслит + безопасные символы) ---
if (!function_exists('slugify_filename')) {
    function slugify_filename(string $name, string $forceExt = ''): string {
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = pathinfo($name, PATHINFO_FILENAME);

        // транслитерация кириллицы → латиница
        static $tr = [
            'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Е'=>'E','Ё'=>'E','Ж'=>'Zh','З'=>'Z','И'=>'I','Й'=>'Y','К'=>'K','Л'=>'L',
            'М'=>'M','Н'=>'N','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U','Ф'=>'F','Х'=>'Kh','Ц'=>'Ts','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Sch',
            'Ъ'=>'','Ы'=>'Y','Ь'=>'','Э'=>'E','Ю'=>'Yu','Я'=>'Ya',
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l',
            'м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch',
            'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'
        ];
        $base = strtr($base, $tr);

        // заменить всё, кроме [A-Za-z0-9_.-] → дефис
        $base = preg_replace('~[^A-Za-z0-9_.-]+~', '-', $base);
        // убрать повторные дефисы и крайние разделители
        $base = preg_replace('~-{2,}~', '-', $base);
        $base = trim($base, '-_.');
        // нижний регистр
        $base = strtolower($base);
        if ($base === '') $base = 'file';

        $finalExt = $forceExt !== '' ? strtolower($forceExt) : $ext;
        if ($finalExt !== '') $base .= '.'.$finalExt;

        return $base;
    }
}

/* ===== ACTIONS ===== */
$action = isset($_REQUEST['action']) ? (string)$_REQUEST['action'] : '';

/* ping */
if ($action === 'ping') { json_ok(['success'=>true,'pong'=>true,'user'=>$modx->user->get('username')]); }

/* бренды */
if ($action === 'list_vendors') {

    // miniShop2 может отсутствовать на другом сайте: не падаем, а объясняем.
    if (!class_exists('msVendor')) {
        json_ok([
            'success' => false,
            'message' => 'miniShop2 model is not available (msVendor class missing). Check that miniShop2 is installed.',
        ]);
    }

    $c = $modx->newQuery('msVendor');
    if (!$c) {
        json_ok(['success' => false, 'message' => 'Cannot create query for msVendor']);
    }

    $c->select(['id','name']);
    $c->sortby('name','ASC');

    $vendors = [];

    if ($c->prepare() && $c->stmt->execute()) {
        while ($r = $c->stmt->fetch(PDO::FETCH_ASSOC)) {
            $vendors[] = ['id' => (int)$r['id'], 'name' => (string)$r['name']];
        }
        json_ok(['success' => true, 'vendors' => $vendors]);
    }

    // Если prepare/execute не прошло - возвращаем ошибку, а не 500
    $errInfo = $c->stmt ? $c->stmt->errorInfo() : null;
    json_ok(['success' => false, 'message' => 'DB error in list_vendors', 'db_error' => $errInfo]);
}


/* папки превью */
if ($action === 'list_folders') {
    // thumbs_base_path из системных настроек (пример: assets/images/thumbs/)
    global $thumbsBasePath;

    $base = rtrim(MODX_BASE_PATH, '/').'/'.trim($thumbsBasePath, '/');
    $dir  = $base;

    $folders = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $d) {
            if ($d === '.' || $d === '..') continue;
            if (is_dir($dir.'/'.$d)) $folders[] = $d;
        }
        sort($folders, SORT_NATURAL | SORT_FLAG_CASE);
    }
    json_ok(['success'=>true,'folders'=>$folders]);
}


/* поиск товара по артикулу */
if ($action === 'lookup_product') {
    $article = getStr($_REQUEST,'article',''); $vendorId = getInt($_REQUEST,'vendor_id',0);
    if ($article==='') json_ok(['success'=>false,'message'=>'Укажите артикул']);
    $q = $modx->newQuery('msProductData'); $q->select(['id','article','vendor']); $q->where(['article'=>$article]);
    $items=[]; if ($q->prepare() && $q->stmt->execute()) {
        while ($r=$q->stmt->fetch(PDO::FETCH_ASSOC)) {
            $rid=(int)$r['id']; $vid=(int)$r['vendor']; if ($vendorId>0 && $vid!==$vendorId) continue;
            $vname=''; if ($vid && ($v=$modx->getObject('msVendor',$vid))) $vname=$v->get('name');
            $items[]=['resource_id'=>$rid,'article'=>$r['article'],'vendor_id'=>$vid,'vendor_name'=>$vname];
        }
    }
    json_ok(['success'=>true,'items'=>$items]);
}

/* список pdf-файлов в папке /assets/<folder>/docs/<folder> */
if ($action === 'list_files') {
    // 1) Нормализуем папку
    $folderRaw = getStr($_REQUEST, 'folder', '');
    $folderRaw = preg_replace('~\s*\(авто\)$~u', '', $folderRaw);
    $folder    = sanitizeFolder($folderRaw);

    $diag = [
        'folder_raw='.$folderRaw,
        'folder_sanitized='.$folder,
    ];

    if ($folder === '') {
        json_ok(['success'=>true,'files'=>[], 'diagnostics'=>$diag]);
    }

    // 2) Абсолютный путь: docs_base_path + folder
    $dir = rtrim($docsBasePath, '/')."/{$folder}/";
    $diag[] = 'abs_dir='.$dir;

    $files = [];
    if (!is_dir($dir)) {
        $diag[] = 'is_dir=false';
        json_ok(['success'=>true,'files'=>[], 'diagnostics'=>$diag]);
    }
    $diag[] = 'is_dir=true';

    $list = @scandir($dir);
    if ($list === false) {
        $diag[] = 'scandir=false';
        json_ok(['success'=>true,'files'=>[], 'diagnostics'=>$diag]);
    }
    $diag[] = 'scandir_count='.count($list);

    foreach ($list as $f) {
        if ($f === '.' || $f === '..') continue;
        if (!preg_match('~\.pdf$~i', $f)) continue;

        // дополнительная защита от странных имён
        if (strpos($f, "\0") !== false || strpos($f, '/') !== false || strpos($f, '\\') !== false) continue;

        $p = $dir . $f;

        $files[] = [
            'name'      => $f,
            'size'      => @filesize($p) ?: 0,
            'mtime'     => @filemtime($p) ?: 0,
            'url'       => pdfu_buildDocUrl($modx, $folder, $f),
            'thumb_url' => pdfu_buildThumbUrl($modx, $folder, preg_replace('~\.pdf$~i', '.jpg', $f)),
        ];
    }

    usort($files, static function($a, $b) {
        return strnatcasecmp($a['name'], $b['name']);
    });

    json_ok(['success'=>true,'files'=>$files, 'diagnostics'=>$diag]);
}


/* поиск документов */
if ($action === 'search_all') {
    $tvName   = getStr($_REQUEST,'tv_name','sertif');
    $article  = getStr($_REQUEST,'article','');
    $vendorId = getInt($_REQUEST,'vendor_id',0);

    // добавили поддержку поиска по resource_id
    $ridReq = (int)($_REQUEST['resource_id'] ?? 0);
    $diag   = [];

    // если задан resource_id — подтянем article/vendor для реестра (если не переданы)
    if ($ridReq > 0) {
        $q = $modx->newQuery('msProductData');
        $q->select(['id','article','vendor']);
        $q->where(['id' => $ridReq]);
        if ($q->prepare() && $q->stmt->execute()) {
            if ($row = $q->stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($article === '' && !empty($row['article'])) $article = (string)$row['article'];
                if ($vendorId <= 0 && !empty($row['vendor']))   $vendorId = (int)$row['vendor'];
                $diag[] = 'search_by=resource_id';
            } else {
                $diag[] = 'resource_id_not_found';
            }
        }
    }

    // если resource_id не задан — требуем артикул, как раньше
    if ($ridReq === 0 && $article === '') {
        json_ok(['success'=>false,'message'=>'Укажите артикул или resource_id']);
    }

    $table = $registryTable;

    /* === РЕЕСТР (по артикулу, если он есть) === */
    $registry = [];
    if ($article !== '') {
        $sql = "SELECT id,article,vendor_id,vendor_name,folder,pdf_url,thumb_url,pdf_name,thumb_name,createdon
                FROM `{$table}` WHERE article=:a".($vendorId>0?' AND vendor_id=:v':'')." ORDER BY createdon DESC";
        $st  = $modx->prepare($sql);
        $st->bindValue(':a',$article);
        if ($vendorId>0) $st->bindValue(':v',$vendorId);
        if ($st && $st->execute()) {
            while($row=$st->fetch(PDO::FETCH_ASSOC)){
                $folder = $row['folder'] ?: folderFromPath((string)$row['pdf_url'],'assets/files/docs')
                       ?: folderFromPath((string)$row['thumb_url'],'assets/images/thumbs')
                       ?: legacyFolderGuess((string)$row['pdf_url'])
                       ?: legacyFolderGuess((string)$row['thumb_url']) ?: 'default';
                $pdfName   = basename((string)($row['pdf_name']   ?: $row['pdf_url']   ?: ''));
                $thumbName = basename((string)($row['thumb_name'] ?: $row['thumb_url'] ?: ''));
                if ($thumbName==='' && $pdfName!=='') $thumbName=preg_replace('~\.[^.]+$~','.jpg',$pdfName);
                $row['folder']   = $folder;
                $row['pdf_url']  = pdfu_buildDocUrl($modx,$folder,$pdfName);
                $row['thumb_url']= pdfu_buildThumbUrl($modx,$folder,$thumbName);
                $row['preview']  = $row['thumb_url'];
                $registry[]      = $row;
            }
        }
    } else {
        $diag[] = 'registry_skipped:no_article';
    }

    /* === MIGX === */
    $migx = [];
    /** @var modTemplateVar $tv */
    $tv = $modx->getObject('modTemplateVar',['name'=>$tvName]);
    if ($tv) {
        if ($ridReq > 0) {
            // точечное чтение MIGX по конкретной карточке
            $tvId = (int)$tv->get('id');
            $link = $modx->getObject('modTemplateVarResource', [
                'tmplvarid' => $tvId,
                'contentid' => $ridReq
            ]);
            if ($link) {
                $val   = (string)$link->get('value');
                $items = $val ? json_decode($val,true) : [];
                if (is_array($items)) {
                    foreach ($items as $it) {
                        $file = (string)($it['file']  ?? '');
                        $img  = (string)($it['image'] ?? '');
                        // вычислим папку по относительным/абсолютным значениям
                        $folder = folderFromPath($file,'assets/files/docs')
                               ?: folderFromPath($img,'assets/images/thumbs')
                               ?: legacyFolderGuess($file) ?: legacyFolderGuess($img) ?: '';
                        $fileUrl = $folder ? pdfu_buildDocUrl($modx,$folder,basename($file)) : absUrl($modx,$file);
                        $imgUrl  = $folder ? pdfu_buildThumbUrl($modx,$folder,basename($img)) : absUrl($modx,$img);
                        $migx[] = [
                            'resource_id'=>$ridReq,
                            'name'       =>$it['name'] ?? '',
                            'file'       =>$fileUrl,
                            'preview'    =>$imgUrl,
                            'vendor_id'  =>0, // обогатим ниже
                            'folder'     =>$folder ?: '',
                            'rel_file'   =>$folder ? (basename($file) ? "{$folder}/".basename($file) : '') : toRelDoc($file),
                            'rel_image'  =>$folder ? (basename($img)  ? "{$folder}/".basename($img)  : '') : toRelThumb($img),
                        ];
                    }
                }
            }
            $diag[] = 'migx_scope=by_resource';
        } else {
            // старое поведение: по артикулу (и опционально по вендору)
            $c = $modx->newQuery('msProductData');
            $c->select(['id','vendor']);
            $c->where(['article'=>$article]);
            if ($c->prepare() && $c->stmt->execute()) {
                while($r=$c->stmt->fetch(PDO::FETCH_ASSOC)){
                    $rid=(int)$r['id']; $vid=(int)$r['vendor'];
                    if ($vendorId>0 && $vid!==$vendorId) continue;
                    $val=$tv->getValue($rid); $items=$val?json_decode($val,true):[];
                    if (is_array($items)) foreach ($items as $it){
                        $file=(string)($it['file']??''); $img=(string)($it['image']??'');
                        $folder = folderFromPath($file,'assets/files/docs')
                               ?: folderFromPath($img,'assets/images/thumbs')
                               ?: legacyFolderGuess($file) ?: legacyFolderGuess($img);
                        $fileUrl = $folder ? pdfu_buildDocUrl($modx,$folder,basename($file)) : absUrl($modx,$file);
                        $imgUrl  = $folder ? pdfu_buildThumbUrl($modx,$folder,basename($img)) : absUrl($modx,$img);
                        $migx[] = [
                            'resource_id'=>$rid,
                            'name'=>$it['name'] ?? '',
                            'file'=>$fileUrl,
                            'preview'=>$imgUrl,
                            'vendor_id'=>$vid,
                            'folder'=>$folder ?: '',
                            'rel_file'=> $folder ? (basename($file)  ? "{$folder}/".basename($file)  : '') : toRelDoc($file),
                            'rel_image'=>$folder ? (basename($img)   ? "{$folder}/".basename($img)   : '') : toRelThumb($img),
                        ];
                    }
                }
            }
        }

        // ---- обогащение MIGX-результатов: pagetitle, article, vendor_name ----
        if (!empty($migx)) {
            $ids = array_values(array_unique(array_map(function($x){ return (int)$x['resource_id']; }, $migx)));

            $titles = []; $articlesMap = []; $vendorIds = []; $vendorNames = [];

            // Заголовки
            $c = $modx->newQuery('modResource');
            $c->select(['id','pagetitle']);
            $c->where(['id:IN' => $ids]);
            if ($c->prepare() && $c->stmt->execute()) {
                while ($r = $c->stmt->fetch(PDO::FETCH_ASSOC)) {
                    $titles[(int)$r['id']] = (string)$r['pagetitle'];
                }
            }

            // Артикулы и vendor_id
            $d = $modx->newQuery('msProductData');
            $d->select(['id','article','vendor']);
            $d->where(['id:IN' => $ids]);
            if ($d->prepare() && $d->stmt->execute()) {
                while ($r = $d->stmt->fetch(PDO::FETCH_ASSOC)) {
                    $rid = (int)$r['id'];
                    $articlesMap[$rid]  = (string)$r['article'];
                    $vendorIds[$rid]    = (int)$r['vendor'];
                }
            }

            // Имена вендоров
            $vids = array_values(array_unique(array_filter(array_map(function($x){ return (int)$x; }, $vendorIds))));
            if ($vids) {
                $v = $modx->newQuery('msVendor');
                $v->select(['id','name']);
                $v->where(['id:IN' => $vids]);
                if ($v->prepare() && $v->stmt->execute()) {
                    while ($r = $v->stmt->fetch(PDO::FETCH_ASSOC)) {
                        $vendorNames[(int)$r['id']] = (string)$r['name'];
                    }
                }
            }

            foreach ($migx as &$m) {
                $rid = (int)$m['resource_id'];
                $m['pagetitle']   = $titles[$rid]       ?? '';
                $m['article']     = $articlesMap[$rid]  ?? '';
                $m['vendor_id']   = $vendorIds[$rid]    ?? 0;
                $m['vendor_name'] = ($m['vendor_id'] ? ($vendorNames[(int)$m['vendor_id']] ?? '') : '');
            }
            unset($m);
        }
    } else {
        $diag[] = "tv_not_found:{$tvName}";
    }

    // корректный JSON-ответ
    json_ok([
        'success'       => true,
        'from_registry' => $registry,
        'from_migx'     => $migx,
        'diagnostics'   => $diag
    ]);
}


/* поиск использования конкретного файла */
if ($action === 'file_usage') {
    $folder = sanitizeFolder(getStr($_REQUEST,'folder',''));
    $name   = trim(getStr($_REQUEST,'name',''));
    $tvName = getStr($_REQUEST,'tv_name','sertif');
    if ($folder === '' || $name === '') {
        json_ok(['success'=>false,'message'=>'Укажите folder и name']);
    }

    $relative = "{$folder}/{$name}";
    $registry = [];
    $diag = [];

    // 1) Реестр
    $table = $registryTable;
    $st = $modx->prepare("SELECT id,article,vendor_id,vendor_name,folder,pdf_name,thumb_name,pdf_url,thumb_url,createdon
                          FROM `{$table}` WHERE folder=:f AND pdf_name=:n ORDER BY createdon DESC");
    if ($st && $st->execute([':f'=>$folder, ':n'=>$name])) {
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $registry[] = [
                'id'          => (int)$r['id'],
                'article'     => $r['article'],
                'vendor_id'   => (int)$r['vendor_id'],
                'vendor_name' => (string)$r['vendor_name'],
                'pdf_name'    => (string)$r['pdf_name'],
                'thumb_name'  => (string)$r['thumb_name'],
                'pdf_url'     => pdfu_buildDocUrl($modx,$folder,$r['pdf_name']),
                'thumb_url'   => ($r['thumb_name'] ? pdfu_buildThumbUrl($modx,$folder,$r['thumb_name']) : ''),
                'createdon'   => $r['createdon'],
            ];
        }
    } else {
        $diag[] = 'registry: query failed';
    }

    // 2) MIGX — ищем в значениях TV по LIKE, затем подтверждаем парсингом JSON
    $migx = [];
    $tv = $modx->getObject('modTemplateVar', ['name' => $tvName]);
    if ($tv) {
        $tvId = (int)$tv->get('id');
        $q = $modx->newQuery('modTemplateVarResource');
        $q->select(['contentid','value']);
        $q->where([
            'tmplvarid' => $tvId,
            // ищем относительный путь
            'value:LIKE' => '%' . $relative . '%'
        ]);
        if ($q->prepare() && $q->stmt->execute()) {
            while ($row = $q->stmt->fetch(PDO::FETCH_ASSOC)) {
                $rid = (int)$row['contentid'];
                $val = (string)$row['value'];
                $items = json_decode($val, true);
                if (!is_array($items)) continue;
                foreach ($items as $it) {
                    $f = toRelDoc((string)($it['file'] ?? ''));
                    if ($f === $relative) {
                        $imgRel = toRelThumb((string)($it['image'] ?? ''));
                        $migx[] = [
                            'resource_id' => $rid,
                            'name'        => (string)($it['name'] ?? ''),
                            'rel_file'    => $f,
                            'file_url'    => pdfu_buildDocUrl($modx, $folder, $name),
                            'preview_url' => $imgRel ? pdfu_buildThumbUrl($modx, $folder, basename($imgRel)) : ''
                        ];
                    }
                }
            }
        } else {
            $diag[] = 'migx: query failed';
        }
        // обогатим данными ресурса (заголовок)
        if (!empty($migx)) {
            $ids = array_values(array_unique(array_map(function($x){ return (int)$x['resource_id']; }, $migx)));
            if ($ids) {
                $c = $modx->newQuery('modResource');
                $c->select(['id','pagetitle']);
                $c->where(['id:IN' => $ids]);
                if ($c->prepare() && $c->stmt->execute()) {
                    $titles = [];
                    while ($r = $c->stmt->fetch(PDO::FETCH_ASSOC)) $titles[(int)$r['id']] = $r['pagetitle'];
                    foreach ($migx as &$m) { $m['pagetitle'] = $titles[$m['resource_id']] ?? ''; }
                    unset($m);
                }
            }
        }
        // Уже есть: $ids (resource_id) и $migx с pagetitle
        // --- Безопасная инициализация ---
        $ids = [];                 // чтобы не было Undefined variable: ids
        $articles = [];
        $vendorIds = [];
        $vendorNames = [];

        // Собираем ids только если MIGX-выдача не пустая
        if (!empty($migx)) {
            $ids = array_values(array_unique(array_map(function($x){
                return (int)($x['resource_id'] ?? 0);
            }, $migx)));
            $ids = array_values(array_filter($ids));
        }

        // msProductData: article + vendor (только если есть ids)
        if (!empty($ids)) {
            $d = $modx->newQuery('msProductData');
            $d->select(['id','article','vendor']);
            $d->where(['id:IN' => $ids]);
            if ($d->prepare() && $d->stmt->execute()) {
                while ($r = $d->stmt->fetch(PDO::FETCH_ASSOC)) {
                    $rid = (int)$r['id'];
                    $articles[$rid]  = (string)$r['article'];
                    $vendorIds[$rid] = (int)$r['vendor'];
                }
            }

            // Имена вендоров
            $vids = array_values(array_unique(array_filter(array_map(function($x){
                return (int)$x;
            }, $vendorIds))));

            if (!empty($vids)) {
                $v = $modx->newQuery('msVendor');
                $v->select(['id','name']);
                $v->where(['id:IN' => $vids]);
                if ($v->prepare() && $v->stmt->execute()) {
                    while ($r = $v->stmt->fetch(PDO::FETCH_ASSOC)) {
                        $vendorNames[(int)$r['id']] = (string)$r['name'];
                    }
                }
            }

            // Вносим в выдачу
            foreach ($migx as &$m) {
                $rid = (int)$m['resource_id'];
                $m['article']     = $articles[$rid]  ?? '';
                $m['vendor_id']   = $vendorIds[$rid] ?? 0;
                $m['vendor_name'] = ($m['vendor_id'] ? ($vendorNames[(int)$m['vendor_id']] ?? '') : '');
            }
            unset($m);
        }


        // Имена вендоров
        $vids = array_values(array_unique(array_filter(array_map(function($x){ return (int)$x; }, $vendorIds))));
        if ($vids) {
            $v = $modx->newQuery('msVendor');
            $v->select(['id','name']);
            $v->where(['id:IN' => $vids]);
            if ($v->prepare() && $v->stmt->execute()) {
                while ($r = $v->stmt->fetch(PDO::FETCH_ASSOC)) {
                    $vendorNames[(int)$r['id']] = (string)$r['name'];
                }
            }
        }

        // Вносим в выдачу
        foreach ($migx as &$m) {
            $rid = (int)$m['resource_id'];
            $m['article']     = $articles[$rid]  ?? '';
            $m['vendor_id']   = $vendorIds[$rid] ?? 0;
            $m['vendor_name'] = ($m['vendor_id'] ? ($vendorNames[(int)$m['vendor_id']] ?? '') : '');
        }
        unset($m);

    } else {
        $diag[] = "tv '{$tvName}' not found";
    }

    json_ok(['success'=>true,'registry'=>$registry,'migx'=>$migx,'diagnostics'=>$diag]);
}


/* удалить из реестра */
if ($action === 'delete_registry') {
    $id = getInt($_REQUEST,'id',0);
    $delFiles = (bool)getInt($_REQUEST,'delete_files',0);
    if ($id <= 0) json_ok(['success'=>false,'message'=>'Нет id']);

    $table = $registryTable;
    $st = $modx->prepare("SELECT * FROM `{$table}` WHERE id=:id");
    $st->bindValue(':id',$id); $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_ok(['success'=>false,'message'=>'Запись не найдена']);

    // пути на диск
    $folder = sanitizeFolder($row['folder'] ?: legacyFolderGuess($row['pdf_url']) ?: 'default');
    $pdf   = basename($row['pdf_name'] ?: $row['pdf_url']);
    $thumb = basename($row['thumb_name'] ?: $row['thumb_url']);
    // $absPdf   = MODX_BASE_PATH."assets/files/docs/{$folder}/{$pdf}";
    // $absThumb = MODX_BASE_PATH."assets/images/thumbs/{$folder}/{$thumb}";
    $absPdf   = rtrim(MODX_BASE_PATH,'/').'/'.trim($docsBasePath,'/')."/{$folder}/{$pdf}";
    $absThumb = rtrim(MODX_BASE_PATH,'/').'/'.trim($thumbsBasePath,'/')."/{$folder}/{$thumb}";

    // удаляем строку
    $d = $modx->prepare("DELETE FROM `{$table}` WHERE id=:id");
    $d->bindValue(':id',$id); $d->execute();

    $files = ['pdf'=>false,'thumb'=>false];
    if ($delFiles) {
        $files['pdf']   = safeUnlink($absPdf);
        $files['thumb'] = safeUnlink($absThumb);
    }

    json_ok(['success'=>true,'deleted_id'=>$id,'files'=>$files]);
}


/* удалить элемент MIGX у товара (без удаления файлов) */
if ($action === 'delete_migx_item') {
    $rid     = getInt($_REQUEST,'resource_id',0);
    $tvName  = getStr($_REQUEST,'tv_name','sertif');
    $relFile = toRelDoc(getStr($_REQUEST,'file',''));     // допустим и абсолютный URL
    $relImg  = toRelThumb(getStr($_REQUEST,'image',''));  // допустим и абсолютный URL

    if ($rid <= 0) json_ok(['success'=>false,'message'=>'Нет resource_id']);

    /** @var modTemplateVar $tv */
    $tv = $modx->getObject('modTemplateVar',['name'=>$tvName]);
    if (!$tv) json_ok(['success'=>false,'message'=>'TV не найдено']);

    $val   = $tv->getValue($rid);
    $items = $val ? json_decode($val,true) : [];
    if (!is_array($items)) $items = [];

    $kept = [];
    $removed = [];
    foreach ($items as $it) {
        $f = toRelDoc((string)($it['file']  ?? ''));
        $i = toRelThumb((string)($it['image'] ?? ''));
        // удаляем элемент, если совпадает файл ИЛИ превью
        if (($relFile && $f === $relFile) || ($relImg && $i === $relImg)) {
            $removed[] = ['file'=>$f,'image'=>$i,'name'=>$it['name'] ?? ''];
            continue;
        }
        $kept[] = $it;
    }

    // сохранить обновлённый список без удалённого элемента
    $tv->setValue($rid, json_encode($kept, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $tv->save();

    // НИЧЕГО НЕ УДАЛЯЕМ С ДИСКА
    json_ok([
        'success' => true,
        'removed' => $removed,
        'note'    => 'Связь удалена из карточки, файлы оставлены на диске'
    ]);
}


/* загрузка PDF + превью + запись в реестр */
if ($action === 'upload_pdf') {
    try {
        $folder     = sanitizeFolder(getStr($_POST,'folder','manuals'));
        $article    = getStr($_POST,'article','');
        $vendorId   = getInt($_POST,'vendor_id',0);
        $title      = getStr($_POST,'title','');
        $resourceId = getInt($_POST,'resource_id',0);
        

        if ($resourceId <= 0 && $article !== '') {
            $q = $modx->newQuery('msProductData');
            $q->select(['id','vendor']);
            $where = ['article' => $article];
            if ($vendorId > 0) $where['vendor'] = $vendorId;
            $q->where($where);
            if ($q->prepare() && $q->stmt->execute()) {
                if ($row = $q->stmt->fetch(PDO::FETCH_ASSOC)) {
                    $resourceId = (int)$row['id'];
                    if ($vendorId <= 0) $vendorId = (int)$row['vendor']; // подхватить бренд, если не был задан
                }
            }
        }

        $vendorName = '';
        if ($vendorId>0 && ($v=$modx->getObject('msVendor',$vendorId))) $vendorName = (string)$v->get('name');

        // проверка наличия файла
        if (empty($_FILES['pdf_file']['tmp_name'])) {
            json_ok(['success'=>false,'message'=>'Файл не получен']);
        }

        // директории назначения
        $docsDir   = rtrim(MODX_BASE_PATH,'/').'/'.trim($docsBasePath,'/')."/{$folder}/";
        $thumbsDir = rtrim(MODX_BASE_PATH,'/').'/'.trim($thumbsBasePath,'/')."/{$folder}/";
        if (!ensureDir($docsDir) || !ensureDir($thumbsDir)) {
            json_ok(['success'=>false,'message'=>'Не удалось создать каталоги']);
        }

        // нормализация имени
        $origName = basename($_FILES['pdf_file']['name'] ?? 'file.pdf');
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION)) ?: 'pdf';
        if ($ext !== 'pdf') {
            json_ok(['success'=>false,'message'=>'Допустим только PDF']);
        }

        // нормализуем имя (транслит + дефисы)
        $pdfName = slugify_filename($origName, 'pdf');
        $pdfPath = $docsDir . $pdfName;

        // защита от коллизий
        if (file_exists($pdfPath)) {
            //$pdfName = slugify_filename(pathinfo($pdfName, PATHINFO_FILENAME) . '-' . time(), 'pdf');
            $pdfName = slugify_filename(pathinfo($pdfName, PATHINFO_FILENAME), 'pdf');
            $pdfPath = $docsDir . $pdfName;
        }

        // перенос файла
        if (!move_uploaded_file($_FILES['pdf_file']['tmp_name'], $pdfPath)) {
            json_ok(['success'=>false,'message'=>'Не удалось сохранить PDF']);
        }

        // превью
        $thumbName = preg_replace('~\.pdf$~i','.jpg',$pdfName);
        $thumbPath = $baseThumb.$thumbName;
        $diag = [];
        $thumbOk = makeJpgPreview($pdfPath, $thumbPath, $diag);


        // ---- Запись в MIGX TV 'sertif' карточки товара ----
        // относительные пути для MIGX + Media Source
        $relativePdf   = "{$folder}/{$pdfName}";
        $relativeThumb = $thumbOk ? "{$folder}/{$thumbName}" : '';

        // абсолютные URL для ответа и реестра
        $pdfUrl   = pdfu_buildDocUrl($modx, $folder, $pdfName);
        $thumbUrl = $thumbOk ? pdfu_buildThumbUrl($modx, $folder, $thumbName) : '';

        $migxUpdated = false;
        if ($resourceId > 0) {
            /** @var modTemplateVar $tv */
            $tv = $modx->getObject('modTemplateVar', ['name' => $tvName]);
            if ($tv) {
                // получить текущие элементы
                $current = $tv->getValue($resourceId);
                $items = $current ? json_decode($current, true) : [];
                if (!is_array($items)) $items = [];

                // добавить новый элемент
                $items[] = [
                    'name'  => ($title !== '' ? $title : $pdfName),
                    'file'  => $relativePdf,        // для MIGX храним относительные пути
                    'image' => $relativeThumb
                ];

                // сохранить
                $tv->setValue($resourceId, json_encode($items, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                if ($tv->save()) {
                    $migxUpdated = true;
                }
            }
        }


        $table = $registryTable;
        $sql="INSERT INTO `{$table}` (article,vendor_id,vendor_name,folder,pdf_name,thumb_name,pdf_url,thumb_url,createdon)
              VALUES (:a,:vid,:vname,:f,:pn,:tn,:pu,:tu,NOW())";
        $st=$modx->prepare($sql);
        $pdfUrl   = pdfu_buildDocUrl($modx,$folder,$pdfName);
        $thumbUrl = $thumbOk ? pdfu_buildThumbUrl($modx,$folder,$thumbName) : '';
        // $cb = '?v='.time(); // простой cache-buster на момент генерации
        $st->execute([
            ':a'=>$article, ':vid'=>$vendorId, ':vname'=>$vendorName, ':f'=>$folder,
            ':pn'=>$pdfName, ':tn'=>$thumbName, ':pu'=>$pdfUrl, ':tu'=>$thumbUrl
        ]);

        json_ok([
            'success'     => true,
            'message'     => $thumbOk ? 'Файл успешно загружен' : 'Файл загружен, превью не создано',
            'pdf_url'     => $pdfUrl,
            'thumb_url'   => $thumbUrl ? $thumbUrl : '',
            'resource_id' => $resourceId,
            'migx_updated'=> $migxUpdated,
            'diagnostics' => $diag
        ]);


    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'upload_pdf error','error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* -------- Массовая загрузка одного PDF для списка артикулов -------- */
if ($action === 'upload_pdf_mass') {
    try {
        $folder     = sanitizeFolder(getStr($_POST,'folder','manuals'));
        $vendorId   = getInt($_POST,'vendor_id',0);
        $massStr    = getStr($_POST,'mass_articles','');
        $title      = getStr($_POST,'title','');
        $vendorName = '';
        if ($vendorId>0 && ($v=$modx->getObject('msVendor',$vendorId))) {
            $vendorName = (string)$v->get('name');
        }

        if (empty($_FILES['pdf_file']['tmp_name'])) {
            json_ok(['success'=>false,'message'=>'Файл не получен']);
        }

        if ($massStr==='') {
            json_ok(['success'=>false,'message'=>'Не указан список артикулов']);
        }

        $articles = preg_split('~[\s,;]+~u', $massStr, -1, PREG_SPLIT_NO_EMPTY);
        $articles = array_values(array_unique(array_map('trim',$articles)));
        $totalInput = count($articles);

        // директории
        $docsDir   = rtrim(MODX_BASE_PATH,'/').'/'.trim($docsBasePath,'/')."/{$folder}/";
        $thumbsDir = rtrim(MODX_BASE_PATH,'/').'/'.trim($thumbsBasePath,'/')."/{$folder}/";
        if (!ensureDir($docsDir) || !ensureDir($thumbsDir)) {
            json_ok(['success'=>false,'message'=>'Не удалось создать каталоги']);
        }
        
        // нормализация имени
        $origName = basename($_FILES['pdf_file']['name'] ?? 'file.pdf');
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION)) ?: 'pdf';
        if ($ext !== 'pdf') {
            json_ok(['success'=>false,'message'=>'Допустим только PDF']);
        }

        $pdfName = slugify_filename($origName, 'pdf');
        $pdfPath = $docsDir . $pdfName;

        // защита от коллизий
        if (file_exists($pdfPath)) {
            //$pdfName = slugify_filename(pathinfo($pdfName, PATHINFO_FILENAME) . '-' . time(), 'pdf');
            $pdfName = slugify_filename(pathinfo($pdfName, PATHINFO_FILENAME), 'pdf');
            $pdfPath = $docsDir . $pdfName;
        }

        // перенос
        if (!move_uploaded_file($_FILES['pdf_file']['tmp_name'], $pdfPath)) {
            json_ok(['success'=>false,'message'=>'Не удалось сохранить PDF']);
        }

        // превью
        $thumbName = preg_replace('~\.pdf$~i','.jpg', $pdfName);
        $thumbPath = $thumbsDir.$thumbName;
        $diag = [];
        $thumbOk = makeJpgPreview($pdfPath, $thumbPath, $diag);


        // относительные для MIGX
        $relativePdf   = "{$folder}/{$pdfName}";
        $relativeThumb = $thumbOk ? "{$folder}/{$thumbName}" : '';
        
        
        // абсолютные URL для ответа и реестра
        $pdfUrl   = pdfu_buildDocUrl($modx, $folder, $pdfName);
        $thumbUrl = $thumbOk ? pdfu_buildThumbUrl($modx, $folder, $thumbName) : '';

        // Реестр
        $table = $registryTable;

        $added = [];        // [{article, resource_id}]
        $skipped = [];      // [article] — товар не найден
        $duplicates = [];   // [article] — уже был прикреплён этот файл

        foreach ($articles as $article) {
            // Найдём карточку
            $resourceId = 0;
            $q = $modx->newQuery('msProductData');
            $q->select(['id','vendor']);
            $where = ['article'=>$article];
            if ($vendorId>0) $where['vendor']=$vendorId;
            $q->where($where);
            if ($q->prepare() && $q->stmt->execute()) {
                if ($row=$q->stmt->fetch(PDO::FETCH_ASSOC)) {
                    $resourceId = (int)$row['id'];
                    if ($vendorId<=0) $vendorId = (int)$row['vendor'];
                }
            }

            if ($resourceId<=0) {
                $skipped[] = $article;
                // даже если товара нет — всё равно фиксируем в реестр (по артикулу), чтобы видеть факт загрузки
                $st=$modx->prepare("INSERT INTO `{$table}` (article,vendor_id,vendor_name,folder,pdf_name,thumb_name,pdf_url,thumb_url,createdon)
                    VALUES (:a,:vid,:vname,:f,:pn,:tn,:pu,:tu,NOW())");
                $st->execute([
                    ':a'=>$article, ':vid'=>$vendorId, ':vname'=>$vendorName, ':f'=>$folder,
                    ':pn'=>$pdfName, ':tn'=>$thumbName, ':pu'=>$pdfUrl, ':tu'=>$thumbUrl
                ]);
                continue;
            }

            // MIGX
            $tv = $modx->getObject('modTemplateVar', ['name' => $tvName]);
            if ($tv) {
                $current = $tv->getValue($resourceId);
                $items = $current ? json_decode($current,true) : [];
                if (!is_array($items)) $items = [];
                $exists = false;
                foreach ($items as $it) {
                    if (toRelDoc((string)($it['file']??'')) === $relativePdf) { $exists=true; break; }
                }
                if ($exists) {
                    $duplicates[] = $article;
                } else {
                    $items[] = [
                        'name'  => ($title ?: $pdfName),
                        'file'  => $relativePdf,
                        'image' => $relativeThumb
                    ];
                    $tv->setValue($resourceId, json_encode($items, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                    $tv->save();
                    $added[] = ['article'=>$article,'resource_id'=>$resourceId];
                }
            }

            // Реестр — фиксируем для видимости истории
            $st=$modx->prepare("INSERT INTO `{$table}` (article,vendor_id,vendor_name,folder,pdf_name,thumb_name,pdf_url,thumb_url,createdon)
                VALUES (:a,:vid,:vname,:f,:pn,:tn,:pu,:tu,NOW())");
            $st->execute([
                ':a'=>$article, ':vid'=>$vendorId, ':vname'=>$vendorName, ':f'=>$folder,
                ':pn'=>$pdfName, ':tn'=>$thumbName, ':pu'=>$pdfUrl, ':tu'=>$thumbUrl
            ]);
        }

        json_ok([
            'success'=>true,
            'message'=>'Файл загружен; смотрите отчёт по артикулам',
            'pdf_url'=>$pdfUrl,
            'thumb_url'=>$thumbUrl,
            'diagnostics'=>$diag,
            'report'=>[
                'added'=>$added,           // [{article, resource_id}]
                'skipped'=>$skipped,       // [article]
                'duplicates'=>$duplicates, // [article]
                'total_input'=>$totalInput,
                'total_added'=>count($added),
                'total_skipped'=>count($skipped),
                'total_duplicates'=>count($duplicates),
            ]
        ]);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'upload_pdf_mass error','error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* bulk_delete_migx_by_file: удалить привязки файла из MIGX у ряда ресурсов */
if ($action === 'bulk_delete_migx_by_file') {
    $folder = sanitizeFolder(getStr($_POST,'folder',''));
    $name   = getStr($_POST,'name','');
    $tvName = getStr($_POST,'tv_name','sertif');
    $all    = (bool)getInt($_POST,'all',0);
    $idsRaw = getStr($_POST,'resource_ids',''); // JSON-массив или пусто
    $resourceIds = [];
    if ($idsRaw !== '') {
        $tmp = json_decode($idsRaw, true);
        if (is_array($tmp)) {
            foreach ($tmp as $v) { $resourceIds[] = (int)$v; }
        }
    }
    if ($folder === '' || $name === '') {
        json_ok(['success'=>false,'message'=>'folder/name не заданы']);
    }
    $relative = "{$folder}/{$name}";

    /** @var modTemplateVar $tv */
    $tv = $modx->getObject('modTemplateVar',['name'=>$tvName]);
    if (!$tv) json_ok(['success'=>false,'message'=>"TV '{$tvName}' не найдено"]);

    // Если all=1, найдём все ресурсы, где встречается этот файл
    if ($all) {
        $tvId = (int)$tv->get('id');
        $q = $modx->newQuery('modTemplateVarResource');
        $q->select(['contentid']);
        $q->where(['tmplvarid'=>$tvId, 'value:LIKE'=>'%'.$relative.'%']);
        if ($q->prepare() && $q->stmt->execute()) {
            while ($r = $q->stmt->fetch(PDO::FETCH_ASSOC)) {
                $resourceIds[] = (int)$r['contentid'];
            }
        }
        $resourceIds = array_values(array_unique(array_filter($resourceIds)));
    }

    if (empty($resourceIds)) {
        json_ok(['success'=>false,'message'=>'Не переданы resource_ids и не выбран all']);
    }

    $affected = [];
    $skipped  = [];
    foreach ($resourceIds as $rid) {
        $val = $tv->getValue($rid);
        $items = $val ? json_decode($val, true) : [];
        if (!is_array($items) || !$items) { $skipped[] = $rid; continue; }

        $kept = [];
        $removedCount = 0;
        foreach ($items as $it) {
            $f = toRelDoc((string)($it['file'] ?? ''));
            if ($f === $relative) { $removedCount++; continue; }
            $kept[] = $it;
        }
        if ($removedCount > 0) {
            $tv->setValue($rid, json_encode($kept, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $tv->save();
            $affected[] = ['resource_id'=>$rid,'removed'=>$removedCount];
        } else {
            $skipped[] = $rid;
        }
    }

    json_ok([
        'success'=>true,
        'message'=>'Удаление выполнено',
        'relative'=>$relative,
        'total_requested'=>count($resourceIds),
        'total_affected'=>count($affected),
        'total_skipped'=>count($skipped),
        'affected'=>$affected,
        'skipped'=>$skipped
    ]);
}

/* bulk_delete_registry_by_file: удалить все строки реестра для данного файла */
if ($action === 'bulk_delete_registry_by_file') {
    $folder = sanitizeFolder(getStr($_POST,'folder',''));
    $name   = getStr($_POST,'name','');
    if ($folder === '' || $name === '') {
        json_ok(['success'=>false,'message'=>'folder/name не заданы']);
    }
    $table = $registryTable;

    // Посчитаем, что удалим
    $cntSt = $modx->prepare("SELECT COUNT(*) FROM `{$table}` WHERE folder=:f AND pdf_name=:n");
    $cntSt->execute([':f'=>$folder, ':n'=>$name]);
    $total = (int)$cntSt->fetchColumn();

    $del = $modx->prepare("DELETE FROM `{$table}` WHERE folder=:f AND pdf_name=:n");
    $del->execute([':f'=>$folder, ':n'=>$name]);

    json_ok(['success'=>true,'message'=>'Реестр очищен','total_deleted'=>$total]);
}


/* unknown */
json_ok(['success'=>false,'message'=>'Unknown action: '.$action]);
