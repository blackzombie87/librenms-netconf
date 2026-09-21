<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Plugin;
use SafferIt\LibrenmsNetconf\NetconfSettings;

/*
 * Base class of the feature tests: LibreNMS's own TestCase (application, database transaction
 * per test) when the suite runs against an installation, otherwise a stub that skips every
 * test, so `composer test` on a bare checkout stays green.
 */
if (class_exists(\LibreNMS\Tests\TestCase::class)) {
    abstract class LibrenmsTestCase extends \LibreNMS\Tests\TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            // the plugin's routes, hooks and commands register only for an enabled plugin;
            // the row is written once, outside the per-test transaction, and the application
            // is booted again so the provider sees it
            if (! Plugin::query()->where('plugin_name', NetconfSettings::PLUGIN_NAME)->where('plugin_active', 1)->exists()) {
                Plugin::query()->updateOrCreate(['plugin_name' => NetconfSettings::PLUGIN_NAME], ['plugin_active' => 1, 'version' => 2]);
                $this->refreshApplication();
            }
            $this->dbSetUp();
        }

        protected function tearDown(): void
        {
            $this->dbTearDown();
            parent::tearDown();
        }
    }
} else {
    abstract class LibrenmsTestCase extends \PHPUnit\Framework\TestCase
    {
        protected function setUp(): void
        {
            $this->markTestSkipped('needs a LibreNMS installation: run phpunit.feature.xml with LIBRENMS_PATH set');
        }
    }
}
