<?php
// _build/data/transport.namespace.php
/** @var modX $modx */

$ns = $modx->newObject('modNamespace');
$ns->fromArray([
    'name' => 'pdfuploader',
    'path' => '{core_path}components/pdfuploader/',
    'assets_path' => '{assets_path}components/pdfuploader/',
], '', true, true);

return $ns;
