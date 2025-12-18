<?php
/** @var xPDOTransport $transport */
/** @var array $options */

if (!isset($transport) || !($transport instanceof xPDOTransport)) {
    return true;
}

$modx = $transport->xpdo;

$act = $options[xPDOTransport::PACKAGE_ACTION] ?? null;
if ($act !== xPDOTransport::ACTION_INSTALL && $act !== xPDOTransport::ACTION_UPGRADE) {
    return true;
}

// Надежнее, чем MODX_BASE_PATH в resolver-контексте
$basePath = rtrim((string)$modx->getOption('base_path'), '/') . '/';

$toRel = function(string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    if (preg_match('~^https?://~i', $v)) {
        $p = parse_url($v, PHP_URL_PATH);
        $v = is_string($p) ? $p : '';
    }
    return trim($v, "/ \t\n\r\0\x0B");
};

$getSettingValue = function(string $key, string $default = '') use ($modx): string {
    $s = $modx->getObject('modSystemSetting', ['key' => $key]);
    if ($s) {
        return (string)$s->get('value');
    }
    return $default;
};

$ensureSetting = function(string $key, string $value = '') use ($modx): modSystemSetting {
    $s = $modx->getObject('modSystemSetting', ['key' => $key]);
    if (!$s) {
        $s = $modx->newObject('modSystemSetting');
        $s->fromArray([
            'key' => $key,
            'namespace' => 'pdfuploader',
            'area' => 'Paths',
            'xtype' => 'textfield',
            'value' => $value,
        ], '', true, true);
        $s->save();
        return $s;
    }
    return $s;
};

// Читаем URL НАПРЯМУЮ из БД (не через getOption)
$docsUrl   = $getSettingValue('pdfuploader.docs_base_url',   'assets/images/docs/');
$thumbsUrl = $getSettingValue('pdfuploader.thumbs_base_url', 'assets/images/thumbs/');

$docsRel   = $toRel($docsUrl);
$thumbsRel = $toRel($thumbsUrl);

// Гарантируем, что keys существуют
$docsPathS   = $ensureSetting('pdfuploader.docs_base_path', '');
$thumbsPathS = $ensureSetting('pdfuploader.thumbs_base_path', '');

if ($docsRel !== '') {
    $docsPathS->set('value', $basePath . $docsRel . '/');
    $docsPathS->save();
}

if ($thumbsRel !== '') {
    $thumbsPathS->set('value', $basePath . $thumbsRel . '/');
    $thumbsPathS->save();
}

// Диагностический лог (временно, чтобы 100% увидеть значения)
$modx->log(modX::LOG_LEVEL_ERROR, '[pdfuploader] resolve.paths executed: base_path=' . $basePath . ' docs_url=' . $docsUrl . ' thumbs_url=' . $thumbsUrl);

return true;
