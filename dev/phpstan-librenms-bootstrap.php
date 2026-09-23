<?php

/*
 * Boots the real LibreNMS for the analysis: its autoloader puts App\Models\Device,
 * LibreNMS\Interfaces\* and the rest in front of anything the plugin declares about them,
 * and Larastan gets the application it wants (models with their relations and casts, the
 * container, the view finder, the console kernel with every command registered).
 *
 * LIBRENMS_PATH names the checkout, the same variable the feature tests use.
 */

$root = getenv('LIBRENMS_PATH');
if (! is_string($root) || ! is_file($root . '/bootstrap/app.php')) {
    fwrite(STDERR, "phpstan-librenms.neon needs LIBRENMS_PATH pointing at a LibreNMS checkout.\n");
    exit(1);
}

/** @var \Composer\Autoload\ClassLoader $librenms */
$librenms = require $root . '/vendor/autoload.php';

// behind this analysis's own autoloader, not in front of it: Composer registers a loader
// prepended, so a package both sides have would be served from the LibreNMS checkout. A
// LibreNMS installed with its dev dependencies (what CI does) brings its own phpstan and
// larastan, and one larastan version calling another's classes ends the run with an
// "Internal error" — the container's LibreNMS is installed without them, so this only ever
// showed in CI. LibreNMS's own namespaces are untouched: the plugin claims none of them
// beyond the module it ships.
$librenms->unregister();
$librenms->register(false);

/** @var \Illuminate\Foundation\Application $app */
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// the two view locations NetconfPluginProvider adds at runtime, so view() arguments are
// checked against the Blade files the plugin actually ships
$app->make('view')->addNamespace('netconf', dirname(__DIR__) . '/resources/views');
$app->make('view')->addLocation(dirname(__DIR__) . '/resources/lnms-views');

if (! defined('LARAVEL_VERSION')) {
    define('LARAVEL_VERSION', $app->version());
}
