<?php

namespace SafferIt\LibrenmsNetconf;

use App\Facades\LibrenmsConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\PortTabHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Console\NetconfDeviceCommand;
use SafferIt\LibrenmsNetconf\Console\NetconfFabricCommand;
use SafferIt\LibrenmsNetconf\Console\NetconfPreviewCommand;
use SafferIt\LibrenmsNetconf\Console\NetconfRunCommand;
use SafferIt\LibrenmsNetconf\Console\NetconfTestCommand;
use SafferIt\LibrenmsNetconf\Console\NetconfUninstallCommand;
use SafferIt\LibrenmsNetconf\Console\NetconfValidateCommand;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionLoader;
use SafferIt\LibrenmsNetconf\Hooks\DeviceOverview;
use SafferIt\LibrenmsNetconf\Hooks\Menu;
use SafferIt\LibrenmsNetconf\Hooks\Page;
use SafferIt\LibrenmsNetconf\Hooks\PortTab;
use SafferIt\LibrenmsNetconf\Hooks\Settings;
use SafferIt\LibrenmsNetconf\Http\DeviceTab\TabRegistration;
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
        // Laravel trims every request string except `password`; the plugin's other secret
        // fields (device form, and the nested settings form) must reach the store untouched
        TrimStrings::except(['key_passphrase', 'settings.password', 'settings.key_passphrase']);

        if ($this->app->runningInConsole()) {
            // available while the plugin is disabled: it is the step before plugin:remove
            $this->commands([NetconfUninstallCommand::class]);
        }

        if (! $pluginManager->pluginEnabled($name)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        // the NETCONF device tab (plan §8 U2); without the core seam the standalone pages stay
        TabRegistration::register(__DIR__ . '/../resources/lnms-views');

        if ($this->app->runningInConsole()) {
            $this->commands([
                NetconfTestCommand::class,
                NetconfRunCommand::class,
                NetconfValidateCommand::class,
                NetconfPreviewCommand::class,
                NetconfDeviceCommand::class,
                NetconfFabricCommand::class,
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
     * Schedule the poller/discovery module. LibreNMS runs a module when its
     * poller_modules/discovery_modules key exists and resolves the class via class_exists(),
     * so the key is only set when the class is loadable. The keys are persisted (not just
     * set in memory) because commands that reload the config would otherwise lose them.
     * plugin:disable does not erase them; Modules\Netconf::should() checks the plugin state
     * instead, and netconf:uninstall --purge removes them.
     */
    private function registerModule(): void
    {
        if (! class_exists(\LibreNMS\Modules\Netconf::class)) {
            return;
        }

        // persisted in the config table: commands that reload the config (device:poll -v)
        // would otherwise lose an in-memory set() made at boot. Checked against the table,
        // not the loaded config: the config cache can still carry a key whose row is gone
        // (seen after a cache-backed boot), and has() would then never persist it again
        $keys = ['poller_modules.netconf', 'discovery_modules.netconf'];
        try {
            $stored = DB::table('config')->whereIn('config_name', $keys)->pluck('config_name')->all();
        } catch (\Throwable) {
            $stored = array_filter($keys, fn ($key) => LibrenmsConfig::has($key));   // no database yet (install)
        }
        foreach (array_diff($keys, $stored) as $key) {
            // persist() answers false without throwing when it could not write (seen at boot);
            // the row is then written directly, in the model's JSON format
            $persisted = false;
            try {
                $persisted = LibrenmsConfig::persist($key, true);
            } catch (\Throwable) {
            }
            if (! $persisted) {
                try {
                    DB::table('config')->updateOrInsert(['config_name' => $key], ['config_value' => json_encode(true)]);
                } catch (\Throwable) {
                }
            }
        }
        foreach ($keys as $key) {
            if (! LibrenmsConfig::has($key)) {
                LibrenmsConfig::set($key, true);   // this process; later boots load the row
            }
        }
    }
}
