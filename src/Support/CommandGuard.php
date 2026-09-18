<?php

namespace SafferIt\LibrenmsNetconf\Support;

use SafferIt\LibrenmsNetconf\Transport\SshCliTransport;

/**
 * Restrictions for operational commands typed into the web form. YAML commands are
 * limited to "show …" by the DefinitionParser; the form is stricter because the
 * monitoring account may have more rights than the read-only class recommended in the
 * README: no command chaining, no pipes other than the display/no-more ones the transport
 * strips anyway, and no configuration dumps (they contain hashed secrets and keys).
 *
 * `lnms netconf:run` is intentionally not guarded: it is an interactive SSH session for
 * whoever can run lnms on the poller.
 */
final class CommandGuard
{
    /** Why the command is not acceptable, or null when it may run. */
    public static function reject(string $command): ?string
    {
        if (preg_match('/[\r\n\x00-\x08\x0B\x0C\x0E-\x1F]/', $command)) {
            return 'must be a single line';
        }

        $normalized = SshCliTransport::normalize($command);
        if (! preg_match('/^show\s+\S/i', $normalized)) {
            return 'only "show …" commands can be run here';
        }
        if (str_contains($normalized, ';') || str_contains($normalized, '|')) {
            return 'pipes and command chaining are not allowed (the plugin appends "| display xml" itself)';
        }
        if (preg_match('/^show\s+conf(i(g(u(r(a(t(i(o(n)?)?)?)?)?)?)?)?)?(\s|$)/i', $normalized)) {
            return '"show configuration" is not available here; use the device CLI';
        }

        return null;
    }
}
