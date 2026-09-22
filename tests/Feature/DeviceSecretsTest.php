<?php

namespace SafferIt\LibrenmsNetconf\Tests\Feature;

use App\Models\Device;
use App\Models\User;
use SafferIt\LibrenmsNetconf\Collect\NetconfService;
use SafferIt\LibrenmsNetconf\Support\DeviceSettings;
use SafferIt\LibrenmsNetconf\Support\SettingsSecrets;
use SafferIt\LibrenmsNetconf\Transport\CredentialResolver;

require_once __DIR__ . '/LibrenmsTestCase.php';

/**
 * Per-device secrets round-trip byte for byte (F5 6): DeviceSettings used to trim every
 * field, so a password with a leading or trailing space authenticated on netconf:test's
 * ad-hoc path but not from the stored copy. The CLI path (apply()) and the HTTP form are
 * both covered; identifiers stay trimmed, all-blank identifiers mean "unchanged".
 */
final class DeviceSecretsTest extends LibrenmsTestCase
{
    public function testSecretsAreStoredUntrimmedThroughApply(): void
    {
        $device = Device::factory()->create(['os' => 'junos']);

        $changes = DeviceSettings::apply($device, ['password' => ' x ', 'key_passphrase' => '   ', 'username' => ' admin ', 'port' => ' 830 ', 'enabled' => '1']);

        $this->assertSame(['netconf_enabled: enabled', 'username: admin', 'password: updated', 'key_passphrase: updated', 'port: 830'], $changes);
        $this->assertSame(' x ', $this->secret($device, 'password'));
        $this->assertSame('   ', $this->secret($device, 'key_passphrase'));   // a whitespace-only secret is a value
        $this->assertSame('admin', $device->getAttrib(CredentialResolver::ATTRIB_PREFIX . 'username'));
        $this->assertSame('1', $device->getAttrib(NetconfService::ATTRIB_ENABLED));

        // blank identifiers leave the stored value alone; clear and inherit are unchanged
        $this->assertSame([], DeviceSettings::apply($device, ['username' => '   ', 'password' => '', 'key_passphrase' => null]));
        $this->assertSame('admin', $device->getAttrib(CredentialResolver::ATTRIB_PREFIX . 'username'));
        $this->assertSame(['netconf_enabled: inherit global default', 'password: removed'], DeviceSettings::apply($device, ['clear' => ['password'], 'enabled' => 'inherit']));
        $this->assertNull($device->getAttrib(CredentialResolver::ATTRIB_PREFIX . 'password'));
        $this->assertSame('   ', $this->secret($device, 'key_passphrase'));
        $this->assertNull(DeviceSettings::enabledAttrib($device));
    }

    public function testTheDeviceFormKeepsTheSurroundingWhitespaceOfSecrets(): void
    {
        $device = Device::factory()->create(['os' => 'junos']);
        $this->actingAs(User::factory()->admin()->create(['enabled' => 1]));

        $this->post("/plugin/netconf/device/$device->device_id", ['password' => ' x ', 'key_passphrase' => ' p ', 'username' => ' admin '])
            ->assertRedirect("/device/$device->device_id/netconf/edit")
            ->assertSessionHas('netconf_result', fn (array $result) => $result['lines'] === ['username: admin', 'password: updated', 'key_passphrase: updated']);

        $device->refresh();
        $this->assertSame(' x ', $this->secret($device, 'password'));
        $this->assertSame(' p ', $this->secret($device, 'key_passphrase'));
        $this->assertSame('admin', $device->getAttrib(CredentialResolver::ATTRIB_PREFIX . 'username'));
    }

    private function secret(Device $device, string $suffix): ?string
    {
        $stored = $device->getAttrib(CredentialResolver::ATTRIB_PREFIX . $suffix);
        if ($stored === null) {
            return null;
        }
        $this->assertTrue(CredentialResolver::isEncrypted($stored));

        return SettingsSecrets::decrypt(substr((string) $stored, strlen(CredentialResolver::SECRET_PREFIX)));
    }
}
