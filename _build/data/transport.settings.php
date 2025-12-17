<?php
// _build/data/transport.settings.php

$def = function(string $key, $value, string $xtype = 'textfield', string $area = 'General') use ($modx) {
    /** @var modSystemSetting $s */
    $s = $modx->newObject('modSystemSetting');
    $s->fromArray([
        'key' => $key,
        'value' => $value,
        'xtype' => $xtype,
        'namespace' => 'pdfuploader',
        'area' => $area,
    ], '', true, true);
    return $s;
};

$settings = [];

// General
$settings[] = $def('pdfuploader.default_folder', 'manuals', 'textfield', 'General');
$settings[] = $def('pdfuploader.tv_name', 'sertif', 'textfield', 'General');
$settings[] = $def('pdfuploader.registry_table', 'pdfuploader_registry', 'textfield', 'General');
$settings[] = $def('pdfuploader.use_registry', 1, 'combo-boolean', 'General');

// Paths (универсально: URL относительные, PATH пустые - заполнит resolver)
$settings[] = $def('pdfuploader.docs_base_url', 'assets/images/docs/', 'textfield', 'Paths');
$settings[] = $def('pdfuploader.docs_base_path', '', 'textfield', 'Paths');

$settings[] = $def('pdfuploader.thumbs_base_url', 'assets/images/thumbs/', 'textfield', 'Paths');
$settings[] = $def('pdfuploader.thumbs_base_path', '', 'textfield', 'Paths');


return $settings;
