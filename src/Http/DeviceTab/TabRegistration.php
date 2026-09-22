<?php

namespace SafferIt\LibrenmsNetconf\Http\DeviceTab;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * Registers the NETCONF device tab through core's PageTabs::$tabsClasses (plan §8 U2), a
 * public static array that core itself treats as the list of tabs — there is no plugin hook
 * for device tabs yet (U5). The tab is inserted before "edit" because the tab bar is the
 * array order, and the tab's Blade file is added to the default view path because core
 * looks it up as `device.tabs.netconf`, where a namespaced view is not found.
 *
 * When the seam is missing (core changed it) the plugin logs once and keeps its standalone
 * pages: the device page must never break because of this plugin.
 */
final class TabRegistration
{
    private static bool $active = false;

    public static function register(string $viewPath): bool
    {
        if (! self::seamAvailable()) {
            self::$active = false;
            Log::warning('netconf: core has no PageTabs::$tabsClasses / DeviceTab seam, the NETCONF device tab is not registered and the standalone pages stay at /plugin/netconf/device');

            return false;
        }

        // idempotent: the application is booted more than once in tests, and the class
        // statics survive that while the view finder does not
        $tabs = \App\View\Components\Device\PageTabs::$tabsClasses;
        unset($tabs['netconf']);
        $entry = ['netconf' => NetconfTab::class];
        $position = array_search('edit', array_keys($tabs), true);
        \App\View\Components\Device\PageTabs::$tabsClasses = $position === false
            ? $tabs + $entry
            : array_slice($tabs, 0, $position, true) + $entry + array_slice($tabs, $position, null, true);
        View::addLocation($viewPath);
        self::$active = true;

        return true;
    }

    public static function active(): bool
    {
        return self::$active;
    }

    private static function seamAvailable(): bool
    {
        if (! class_exists(\App\View\Components\Device\PageTabs::class) || ! interface_exists(\LibreNMS\Interfaces\UI\DeviceTab::class)) {
            return false;
        }
        try {
            $property = new \ReflectionProperty(\App\View\Components\Device\PageTabs::class, 'tabsClasses');
        } catch (\ReflectionException) {
            return false;
        }

        return $property->isStatic() && $property->isPublic() && is_array($property->getValue());
    }
}
