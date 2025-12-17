<?php
// _build/resolvers/resolve.clearcache.php
/** @var modX $modx */
/** @var array $options */

if (!isset($object) || !($object instanceof xPDOTransport)) {
    return true;
}

$action = $options[xPDOTransport::PACKAGE_ACTION] ?? null;
if ($action !== xPDOTransport::ACTION_INSTALL && $action !== xPDOTransport::ACTION_UPGRADE) {
    return true;
}

$modx->cacheManager->refresh([
    'system_settings' => [],
    'resource' => [],
    'default' => [],
]);

$modx->log(modX::LOG_LEVEL_INFO, "🧹 Кэш MODX успешно очищен после установки pdfuploader.");

return true;
