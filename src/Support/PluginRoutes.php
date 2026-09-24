<?php

namespace SafferIt\LibrenmsNetconf\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use LibreNMS\Util\Notifications;

/**
 * Whether the plugin's own routes are registered (plan §9.1 I1/I3).
 *
 * `ServiceProvider::loadRoutesFrom()` does nothing while LibreNMS has a cached route file, but
 * the hooks are published on every request: `lnms plugin:add` installs the package without
 * refreshing that cache (core refreshes it in `plugin:enable` only), so the first install on a
 * production poller rendered a menu entry for a route that did not exist — and because the menu
 * is core's own partial, that took every page down, not just the plugin's.
 *
 * Every hook asks here before it renders, so a missing route cache costs the plugin's UI and
 * nothing else, and the admin is told what to run.
 */
final class PluginRoutes
{
    /** The route that exists whenever routes/web.php was loaded. */
    public const CANARY = 'netconf.status';

    /** The path that route is registered at, for links built without the route name. */
    public const FALLBACK_PATH = 'plugin/netconf';

    public const NOTIFICATION_TITLE = 'NETCONF plugin: routes are missing';

    private static bool $warned = false;

    /** Not memoised: the test suite boots the application more than once per process. */
    public static function available(): bool
    {
        return Route::has(self::CANARY);
    }

    /**
     * A link into the plugin, by route name while that is possible and by path otherwise.
     *
     * @param  array<int|string, mixed>|int|string  $parameters
     */
    public static function to(string $name, array|int|string $parameters = []): string
    {
        return Route::has($name) ? route($name, $parameters) : url(self::FALLBACK_PATH);
    }

    /**
     * Tell the admin what to run, once per process and once in the notification list. Called
     * after every provider has booted, so a cached route file that merely loads late is not
     * mistaken for a missing one.
     */
    public static function warnIfMissing(bool $routesAreCached): void
    {
        if (self::$warned || ! $routesAreCached || self::available()) {
            return;
        }

        self::$warned = true;
        $message = 'The NETCONF plugin is installed and enabled, but its routes are not in the cached route file, '
            . 'so its pages and menu entry are unavailable. Run "lnms route:cache" (or "lnms plugin:enable netconf") '
            . 'to rebuild the cache. This happens after "lnms plugin:add", which does not refresh it.';

        Log::warning('netconf: ' . $message);

        try {
            Notifications::create(self::NOTIFICATION_TITLE, $message, 'netconf', 2);
        } catch (\Throwable $e) {
            // no database yet, or notifications unavailable: the log line is the fallback
            Log::debug('netconf: could not create the route-cache notification: ' . $e->getMessage());
        }
    }
}
