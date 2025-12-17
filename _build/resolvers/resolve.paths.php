<?php
if (!isset($object) || !($object instanceof xPDOTransport)) return true;
$modx = $object->xpdo;

$act = $options[xPDOTransport::PACKAGE_ACTION] ?? null;
if ($act !== xPDOTransport::ACTION_INSTALL && $act !== xPDOTransport::ACTION_UPGRADE) return true;

$basePath = rtrim(MODX_BASE_PATH, '/') . '/';

$toRel = function(string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    if (preg_match('~^https?://~i', $v)) {
        $p = parse_url($v, PHP_URL_PATH);
        $v = is_string($p) ? $p : '';
    }
    return trim($v, "/ \t\n\r\0\x0B");
};

$ensure = function(string $key): modSystemSetting {
    global $modx;
    $s = $modx->getObject('modSystemSetting', ['key' => $key]);
    if (!$s) {
        $s = $modx->newObject('modSystemSetting');
        $s->fromArray([
            'key' => $key,
            'namespace' => 'pdfuploader',
            'area' => 'Paths',
            'xtype' => 'textfield',
            'value' => '',
        ], '', true, true);
        $s->save();
    }
    return $s;
};

$docsUrl   = (string)$modx->getOption('pdfuploader.docs_base_url', null, '');
$thumbsUrl = (string)$modx->getOption('pdfuploader.thumbs_base_url', null, '');

$docsRel   = $toRel($docsUrl);
$thumbsRel = $toRel($thumbsUrl);

if ($docsRel !== '') {
    $s = $ensure('pdfuploader.docs_base_path');
    $s->set('value', $basePath . $docsRel . '/');
    $s->save();
}

if ($thumbsRel !== '') {
    $s = $ensure('pdfuploader.thumbs_base_path');
    $s->set('value', $basePath . $thumbsRel . '/');
    $s->save();
}

return true;
