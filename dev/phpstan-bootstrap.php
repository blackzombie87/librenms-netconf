<?php

/*
 * Larastan expects to boot the application it analyses (its own bootstrap.php looks for
 * bootstrap/app.php or Testbench). This package is a LibreNMS plugin: the application is
 * LibreNMS, which is not part of the checkout, and booting it would need its database.
 *
 * A bare Foundation application with the few providers Larastan resolves while analysing
 * (config, filesystem, events, view) is enough, and it keeps the analysis independent of a
 * LibreNMS checkout, so a fresh clone and CI see what a developer sees.
 */

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\ViewServiceProvider;

$app = new Application(dirname(__DIR__));
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('app', $app);
$app->instance(Container::class, $app);
$app->instance(ApplicationContract::class, $app);
$app->instance('config', new Repository([
    'view' => ['paths' => [dirname(__DIR__) . '/resources/views'], 'compiled' => sys_get_temp_dir()],
]));

foreach ([EventServiceProvider::class, FilesystemServiceProvider::class, ViewServiceProvider::class] as $provider) {
    $app->register($provider);
}

// the two view locations the service provider adds at runtime, so Larastan can check every
// view() argument against the Blade files that are actually shipped
$app->make('view')->addNamespace('netconf', dirname(__DIR__) . '/resources/views');
$app->make('view')->addLocation(dirname(__DIR__) . '/resources/lnms-views');

if (! defined('LARAVEL_VERSION')) {
    define('LARAVEL_VERSION', $app->version());
}
