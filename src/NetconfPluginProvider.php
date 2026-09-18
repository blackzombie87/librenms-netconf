<?php

namespace SafferIt\LibrenmsNetconf;

use App\Facades\LibrenmsConfig;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\PortTabHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Console\NetconfDeviceCommand;
use SafferIt\LibrenmsNetconf\Console\NetconfPreviewCommand;
use SafferIt\LibrenmsNetconf\Console\NetconfRunCommand;
use SafferIt\LibrenmsNetconf\Console\NetconfTestCommand;
use SafferIt\LibrenmsNetconf\Console\NetconfValidateCommand;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionLoader;
use SafferIt\LibrenmsNetconf\Hooks\DeviceOverview;
use SafferIt\LibrenmsNetconf\Hooks\Menu;
use SafferIt\LibrenmsNetconf\Hooks\Page;
use SafferIt\LibrenmsNetconf\Hooks\PortTab;
use SafferIt\LibrenmsNetconf\Hooks\Settings;
use SafferIt\LibrenmsNetconf\Support\SettingsSecrets;
use SafferIt\LibrenmsNetconf\Transport\CredentialResolver;
use SafferIt\LibrenmsNetconf\Transport\DeviceCredentials;
use SafferIt\LibrenmsNetconf\Transport\TransportFactory;

class NetconfPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/netconf.php', 'netconf');

        $this->app->singleton(CredentialResolver::class, fn () => new CredentialResolver(SettingsSecrets::decrypt(...)));
        $this->app->singleton(DeviceCredentials::class, fn ($app) => new DeviceCredentials($app->make(CredentialResolver::class)));
        $this->app->singleton(TransportFactory::class);
        $this->app->singleton(DefinitionLoader::class, fn () => new DefinitionLoader(self::definitionDirectories()));
        $this->app->singleton(NetconfService::class, fn ($app) => new NetconfService(
            $app->make(DefinitionLoader::class),
            $app->make(DeviceCredentials::class),
            $app->make(TransportFactory::class),
        ));
    }

    public function boot(PluginManagerInterface $pluginManager): void
    {
        $name = NetconfSettings::PLUGIN_NAME;

        $pluginManager->publishHook($name, SettingsHook::class, Settings::class);
        $pluginManager->publishHook($name, MenuEntryHook::class, Menu::class);
        $pluginManager->publishHook($name, SinglePageHook::class, Page::class);
        $pluginManager->publishHook($name, DeviceOverviewHook::class, DeviceOverview::class);
        $pluginManager->publishHook($name, PortTabHook::class, PortTab::class);
        $this->loadViewsFrom(__DIR__ . '/../resources/views', $name);
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        SettingsSecrets::register();

        if (! $pluginManager->pluginEnabled($name)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                NetconfTestCommand::class,
                NetconfRunCommand::class,
                NetconfValidateCommand::class,
                NetconfPreviewCommand::class,
                NetconfDeviceCommand::class,
            ]);
        }

        $this->registerModule();
    }

    /**
     * Shipped definitions first, the user directory (setting definitions_dir, default
     * storage/app/netconf-definitions) last so it can override by name.
     *
     * @return list<string>
     */
    public static function definitionDirectories(): array
    {
        $dirs = [DefinitionLoader::shippedDirectory()];
        $user = (string) (NetconfSettings::effective()['definitions_dir'] ?? '');
        if ($user === '' && function_exists('storage_path')) {
            $user = storage_path('app/netconf-definitions');
        }
        if ($user !== '') {
            $dirs[] = $user;
        }

        return $dirs;
    }

    /**
     * Schedule the poller/discovery module once it ships (Phase 3). LibreNMS runs a module
     * when the config key exists; the class is resolved via class_exists(), so registering
     * the key without the class would make every poll log a missing module.
     */
    private function registerModule(): void
    {
        if (! class_exists(\LibreNMS\Modules\Netconf::class)) {
            return;
        }

        foreach (['poller_modules.netconf', 'discovery_modules.netconf'] as $key) {
            if (LibrenmsConfig::has($key)) {
                continue;
            }
            // persist to the config table: commands that reload the config (device:poll -v)
            // would otherwise lose an in-memory set() made at boot
            try {
                LibrenmsConfig::persist($key, true);
            } catch (\Throwable) {
                LibrenmsConfig::set($key, true);
            }
        }
    }
}
