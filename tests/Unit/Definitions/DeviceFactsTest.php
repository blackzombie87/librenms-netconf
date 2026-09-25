<?php

use SafferIt\LibrenmsNetconf\Definitions\DefinitionLoader;
use SafferIt\LibrenmsNetconf\Definitions\DefinitionMatcher;
use SafferIt\LibrenmsNetconf\Definitions\DeviceFacts;

/** The shipped definition itself, so the test fails if its `attrib:` gate is ever renamed. */
function macDefinition(): array
{
    $all = (new DefinitionLoader([DefinitionLoader::shippedDirectory()]))->all();

    return [$all['junos-evpn-fabric-mac']];
}

function matchesMac(array $attribs, array $settings): bool
{
    $facts = (new DeviceFacts(os: 'junos', attribs: $attribs))->withManagedAttribs($settings);

    return (new DefinitionMatcher)->matching($facts, macDefinition()) !== [];
}

it('inherits a managed attribute from the global setting', function () {
    // plan §13.2: absent attribute = the setting; an explicit value on the device always wins
    expect(matchesMac([], ['evpn_mac' => true]))->toBeTrue()
        ->and(matchesMac([], ['evpn_mac' => false]))->toBeFalse()
        ->and(matchesMac(['netconf_evpn_mac' => '0'], ['evpn_mac' => true]))->toBeFalse()
        ->and(matchesMac(['netconf_evpn_mac' => '1'], ['evpn_mac' => false]))->toBeTrue()
        // an empty attribute value is not a decision, so the setting still applies
        ->and(matchesMac(['netconf_evpn_mac' => ''], ['evpn_mac' => true]))->toBeTrue()
        // a setting the install never saved is absent, and absent is off
        ->and(matchesMac([], []))->toBeFalse();
});

it('resolves every managed attribute to a yes or a no and leaves the others alone', function () {
    $facts = (new DeviceFacts(os: 'junos', attribs: ['netconf_enabled' => '1', 'netconf_queues' => '1']))
        ->withManagedAttribs(['evpn_mac' => true, 'queues' => false]);

    expect($facts->attribs)->toBe(['netconf_enabled' => '1', 'netconf_queues' => '1', 'netconf_evpn_mac' => '1'])
        ->and($facts->os)->toBe('junos')
        ->and(array_keys(DeviceFacts::MANAGED_ATTRIBS))->toBe(['netconf_evpn_mac', 'netconf_queues']);
});

it('reads one managed attribute without building the facts', function () {
    expect(DeviceFacts::managed('netconf_queues', null, ['queues' => true]))->toBeTrue()
        ->and(DeviceFacts::managed('netconf_queues', '0', ['queues' => true]))->toBeFalse()
        ->and(DeviceFacts::managed('netconf_queues', null, ['queues' => false]))->toBeFalse()
        ->and(DeviceFacts::managed('netconf_evpn_mac', '1', []))->toBeTrue();
});
