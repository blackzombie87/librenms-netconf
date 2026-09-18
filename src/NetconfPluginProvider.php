<?php

namespace SafferIt\LibrenmsNetconf;

use App\Facades\LibrenmsConfig;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use SafferIt\LibrenmsNetconf\Console\NetconfRunCommand;
use SafferIt\LibrenmsNetconf\Console\NetconfTestCommand;
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
    }

    public function boot(PluginManagerInterface $pluginManager): void
    {
        $name = NetconfSettings::PLUGIN_NAME;

        $pluginManager->publishHook($name, SettingsHook::class, Settings::class);
        $this->loadViewsFrom(__DIR__ . '/../resources/views', $name);
        SettingsSecrets::register();

        if (! $pluginManager->pluginEnabled($name)) {
            return;
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                NetconfTestCommand::class,
                NetconfRunCommand::class,
            ]);
        }

        $this->registerModule();
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
            LibrenmsConfig::set($key, LibrenmsConfig::get($key, true));
        }
    }
}
