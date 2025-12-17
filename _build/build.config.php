<?php
// _build/build.config.php
return [
    'pkg_name'        => 'pdfuploader',
    'pkg_name_lower'  => 'pdfuploader',
    'pkg_version'     => '1.0.0',
    'pkg_release'     => 'pl',
    'pkg_namespace'   => 'pdfuploader',

    // Paths
    'root'            => dirname(__DIR__),                 // project root
    'build'           => __DIR__,
    'resolvers'       => __DIR__ . '/resolvers/',
    'data'            => __DIR__ . '/data/',

    // Where component lives in repo
    'source_core'     => dirname(__DIR__) . '/core/components/pdfuploader/',
    'source_assets'   => dirname(__DIR__) . '/assets/components/pdfuploader/',

    // Where it will be installed
    'target_core'     => MODX_CORE_PATH . 'components/pdfuploader/',
    'target_assets'   => MODX_ASSETS_PATH . 'components/pdfuploader/',
];
