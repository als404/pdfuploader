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

$useRegistry = (int)$modx->getOption('pdfuploader.use_registry', null, 1);
if (!$useRegistry) {
    $modx->log(modX::LOG_LEVEL_ERROR, '[pdfuploader] resolve.tables skipped: use_registry=0');
    return true;
}

$tableName = ($modx->getOption('table_prefix') ?? '') .
    $modx->getOption('pdfuploader.registry_table', null, 'pdfuploader_registry');

$sql = "CREATE TABLE IF NOT EXISTS `{$tableName}` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `article` VARCHAR(191) NOT NULL DEFAULT '',
  `vendor_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `vendor_name` VARCHAR(191) NOT NULL DEFAULT '',
  `folder` VARCHAR(191) NOT NULL DEFAULT '',
  `pdf_name` VARCHAR(191) NOT NULL DEFAULT '',
  `thumb_name` VARCHAR(191) NOT NULL DEFAULT '',
  `pdf_url` TEXT NULL,
  `thumb_url` TEXT NULL,
  `pdf_hash` CHAR(40) NOT NULL DEFAULT '',
  `createdon` DATETIME NULL,
  `updatedon` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `article` (`article`),
  KEY `vendor_id` (`vendor_id`),
  KEY `pdf_name` (`pdf_name`),
  KEY `pdf_hash` (`pdf_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

$modx->exec($sql);
$modx->log(modX::LOG_LEVEL_ERROR, "✔ [pdfuploader] resolve.tables executed, table={$tableName}");

return true;
