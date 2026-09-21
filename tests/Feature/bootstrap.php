<?php

/*
 * Bootstrap of the feature suite (phpunit.feature.xml): with LIBRENMS_PATH set, the LibreNMS
 * installation's autoloader takes precedence (its Laravel, its App\ classes, its copy of
 * this plugin) and its legacy includes are loaded as LibreNMS's own tests/bootstrap.php does.
 * Without it the suite loads, and every test skips itself (LibrenmsTestCase).
 */

$plugin = dirname(__DIR__, 2);
$librenms = getenv('LIBRENMS_PATH') ?: '';

if ($librenms !== '' && is_file("$librenms/vendor/autoload.php")) {
    $librenms = rtrim($librenms, '/');
    $_ENV['APP_BASE_PATH'] = $_SERVER['APP_BASE_PATH'] = $librenms;
    putenv("APP_BASE_PATH=$librenms");
    chdir($librenms);

    /** @var \Composer\Autoload\ClassLoader $loader */
    $loader = require "$librenms/vendor/autoload.php";
    $loader->unregister();
    $loader->register(true);   // before the plugin's own vendor/ that the phpunit binary loaded

    if (is_file("$librenms/tests/bootstrap.php")) {
        require_once "$librenms/tests/bootstrap.php";
    }
} else {
    require_once "$plugin/vendor/autoload.php";
}
