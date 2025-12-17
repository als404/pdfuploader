<?php
/**
 * pdfuploader package builder (no modAction; legacy manager controller scheme)
 */

require_once dirname(__DIR__, 2) . '/config.core.php';
require_once MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';
require_once MODX_CORE_PATH . 'model/modx/transport/modpackagebuilder.class.php';

$modx = new modX();
$modx->initialize('mgr');

$pkgNameLower = 'pdfuploader';
$pkgVersion   = '1.0.0';
$pkgRelease   = 'pl';

$root = dirname(__DIR__);            // .../pdfuploader
$build = __DIR__;
$data = $build . '/data/';
$resolvers = $build . '/resolvers/';

// source in repo
$srcCore   = $root . '/core/components/pdfuploader/';
$srcAssets = $root . '/assets/components/pdfuploader/';

// install targets
$tgtCore   = MODX_CORE_PATH   . 'components/pdfuploader/';
$tgtAssets = MODX_ASSETS_PATH . 'components/pdfuploader/';

$builder = new modPackageBuilder($modx);
$builder->createPackage($pkgNameLower, $pkgVersion, $pkgRelease);
$builder->registerNamespace('pdfuploader', false, true, '{core_path}components/pdfuploader/');

/** ---------------------------------
 *  1) Namespace (ONE vehicle) + files + resolvers
 *  --------------------------------- */
$namespace = require $data . 'transport.namespace.php';

$vehicle = $builder->createVehicle($namespace, [
    xPDOTransport::UNIQUE_KEY    => 'name',
    xPDOTransport::PRESERVE_KEYS => true,
    xPDOTransport::UPDATE_OBJECT => true,
]);

// Put component files
$vehicle->resolve('file', [
    'source' => $srcCore,
    'target' => "return '{$tgtCore}';",
]);
$vehicle->resolve('file', [
    'source' => $srcAssets,
    'target' => "return '{$tgtAssets}';",
]);

// Resolvers (paths + registry table + cache)
$vehicle->resolve('php', ['source' => $resolvers . 'resolve.paths.php']);
$vehicle->resolve('php', ['source' => $resolvers . 'resolve.tables.php']);
$vehicle->resolve('php', ['source' => $resolvers . 'resolve.clearcache.php']);

$builder->putVehicle($vehicle);

/** ---------------------------------
 *  2) System Settings (MANY vehicles, one per setting)
 *  --------------------------------- */
$settings = require $data . 'transport.settings.php';

foreach ($settings as $setting) {
    $vehicle = $builder->createVehicle($setting, [
        xPDOTransport::UNIQUE_KEY    => 'key',
        xPDOTransport::PRESERVE_KEYS => true,
        xPDOTransport::UPDATE_OBJECT => true,
    ]);
    $builder->putVehicle($vehicle);
}

/** ---------------------------------
 *  3) Menu (ONE vehicle)
 *  --------------------------------- */
$menu = require $data . 'transport.menu.php';

$vehicle = $builder->createVehicle($menu, [
    xPDOTransport::UNIQUE_KEY    => 'text',
    xPDOTransport::PRESERVE_KEYS => true,
    xPDOTransport::UPDATE_OBJECT => true,
]);
$builder->putVehicle($vehicle);

// Package attributes (optional)
$builder->setPackageAttributes([
    'license'   => '',
    'readme'    => '',
    'changelog' => '',
]);

$builder->pack();

echo "Package built: {$pkgNameLower}-{$pkgVersion}-{$pkgRelease}\n";
