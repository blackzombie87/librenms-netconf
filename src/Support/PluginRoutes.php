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
 * Every hook asks `availableOrWarn()` before it renders, so a missing route cache costs the
 * plugin's UI and nothing else, and the admin is told what to run — at the point of use, which
 * is the only moment the answer is reliable (plan §10.9).
 */
final class PluginRoutes
{
    /** The route that exists whenever routes/web.php was loaded. */
    public const CANARY = 'netconf.status';

    /** The path that route is registered at, for links built without the route name. */
    public const FALLBACK_PATH = 'plugin/netconf';

    public const NOTIFICATION_TITLE = 'NETCONF plugin: routes are missing';

    private static bool $warned = false;

    /** Whether the application booted with a cached route file; false until a provider says. */
    private static bool $routesAreCached = false;

    /** Not memoised: the test suite boots the application more than once per process. */
    public static function available(): bool
    {
        return Route::has(self::CANARY);
    }

    /**
     * What every hook asks before it renders: are the routes there, and if not, say so once.
     *
     * The answer is only reliable here. Laravel does not load a cached route file during
     * boot() — `RouteServiceProvider::register()` queues a booted() callback which queues
     * another one that finally requires the file — and booted() is a FIFO queue that keeps
     * growing while it is drained, so a check queued from a provider's boot() may run on
     * either side of that require. 1.2.1 checked there and told a healthy production poller
     * to run `lnms route:cache` on every request and every poll (plan §10.9). A render is
     * safely after the require, and in a poller process nothing renders, which is also where
     * the route cache is irrelevant.
     */
    public static function availableOrWarn(): bool
    {
        if (self::available()) {
            return true;
        }

        self::warnIfMissing();

        return false;
    }

    /** Set from the plugin provider's boot(): the file either exists at that point or it does not. */
    public static function rememberRouteCache(bool $routesAreCached): void
    {
        self::$routesAreCached = $routesAreCached;
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
     * Tell the admin what to run, once per process and once in the notification list. Only
     * while the routes come from a cached file: that is the state the message describes, and
     * it is the only one `lnms route:cache` fixes.
     */
    public static function warnIfMissing(): void
    {
        if (self::$warned || ! self::$routesAreCached || self::available()) {
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
