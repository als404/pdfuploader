<?php
// _build/data/transport.menu.php
/** @var modX $modx */

$menu = $modx->newObject('modMenu');
$menu->fromArray([
    'text' => 'Документы PDF',
    'parent' => 'components',
    'description' => 'Централизованная загрузка и привязка PDF документов',
    'icon' => 'icon-file-pdf-o',
    'menuindex' => 0,
    'namespace' => 'pdfuploader',
    'action' => 'index',
], '', true, true);

return $menu;
