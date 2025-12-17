<?php
if (!isset($object) || !($object instanceof xPDOTransport)) return true;

$action = $options[xPDOTransport::PACKAGE_ACTION] ?? null;
if ($action !== xPDOTransport::ACTION_INSTALL && $action !== xPDOTransport::ACTION_UPGRADE) return true;

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

$docsUrl   = (string)$modx->getOption('pdfuploader.docs_base_url', null, '');
$thumbsUrl = (string)$modx->getOption('pdfuploader.thumbs_base_url', null, '');

$docsRel   = $toRel($docsUrl);
$thumbsRel = $toRel($thumbsUrl);

if ($docsRel !== '') {
    if ($s = $modx->getObject('modSystemSetting', ['key' => 'pdfuploader.docs_base_path'])) {
        $s->set('value', $basePath . $docsRel . '/');
        $s->save();
    }
}
if ($thumbsRel !== '') {
    if ($s = $modx->getObject('modSystemSetting', ['key' => 'pdfuploader.thumbs_base_path'])) {
        $s->set('value', $basePath . $thumbsRel . '/');
        $s->save();
    }
}

return true;
