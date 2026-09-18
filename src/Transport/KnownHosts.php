<?php

namespace SafferIt\LibrenmsNetconf\Transport;

/**
 * OpenSSH known_hosts lookup for server host keys (pure PHP, unit-tested).
 *
 * Supports plain and hashed (|1|salt|hmac) host fields, the "[host]:port" form for
 * non-default ports, comma-separated patterns with "*" / "?" wildcards and "!" negation,
 * and the @revoked marker. @cert-authority lines are ignored (no certificate support).
 * Keys are compared by their base64 blob, so the signature algorithm phpseclib reports
 * for RSA (rsa-sha2-512) versus the "ssh-rsa" in the file does not matter.
 */
final class KnownHosts
{
    public const OK = 'ok';

    public const UNKNOWN = 'unknown';

    public const MISMATCH = 'mismatch';

    public const REVOKED = 'revoked';

    public function __construct(private readonly string $file)
    {
    }

    public function file(): string
    {
        return $this->file;
    }

    public function isReadable(): bool
    {
        return is_file($this->file) && is_readable($this->file);
    }

    /**
     * @param  string  $hostKey  "type base64blob" as phpseclib's getServerPublicHostKey() returns it
     * @return string one of OK, UNKNOWN, MISMATCH, REVOKED
     */
    public function check(string $host, int $port, string $hostKey): string
    {
        $blob = self::blob($hostKey);
        $seen = false;

        foreach ($this->entries() as [$marker, $patterns, $entryBlob]) {
            if (! self::hostMatches($patterns, $host, $port)) {
                continue;
            }
            if ($marker === '@cert-authority') {
                continue;
            }
            if ($marker === '@revoked') {
                if ($entryBlob === $blob) {
                    return self::REVOKED;
                }
                continue;
            }
            $seen = true;
            if ($entryBlob === $blob) {
                return self::OK;
            }
        }

        return $seen ? self::MISMATCH : self::UNKNOWN;
    }

    /** known_hosts line for this key, to paste into the file. */
    public static function line(string $host, int $port, string $hostKey): string
    {
        $blob = self::blob($hostKey);

        return sprintf('%s %s %s', self::hostField($host, $port), self::typeFromBlob($blob) ?? strtok($hostKey, ' '), $blob);
    }

    /** OpenSSH-style fingerprint, e.g. "SHA256:8JbYw…" (unpadded base64). */
    public static function fingerprint(string $hostKey): string
    {
        $raw = base64_decode(self::blob($hostKey), true);

        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $raw === false ? '' : $raw, true)), '=');
    }

    /** Key type as embedded in the blob ("ssh-ed25519", "ssh-rsa", "ecdsa-sha2-nistp256"). */
    public static function typeFromBlob(string $blob): ?string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 4) {
            return null;
        }
        $len = unpack('N', $raw)[1] ?? 0;
        if (! is_int($len) || $len <= 0 || $len > 64 || strlen($raw) < 4 + $len) {
            return null;
        }

        return substr($raw, 4, $len);
    }

    public static function hostField(string $host, int $port): string
    {
        return $port === 22 ? $host : sprintf('[%s]:%d', $host, $port);
    }

    private static function blob(string $hostKey): string
    {
        $parts = preg_split('/\s+/', trim($hostKey)) ?: [];

        // "type blob [comment]" or just "blob"
        return count($parts) >= 2 ? $parts[1] : ($parts[0] ?? '');
    }

    /**
     * @return list<array{string, string, string}> marker ('' / @revoked / @cert-authority), host patterns, key blob
     */
    private function entries(): array
    {
        if (! $this->isReadable()) {
            return [];
        }

        $entries = [];
        foreach (preg_split('/\R/', (string) file_get_contents($this->file)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $fields = preg_split('/\s+/', $line) ?: [];
            $marker = '';
            if (($fields[0] ?? '') !== '' && $fields[0][0] === '@') {
                $marker = array_shift($fields);
            }
            if (count($fields) < 3) {
                continue;
            }
            $entries[] = [$marker, $fields[0], $fields[2]];
        }

        return $entries;
    }

    private static function hostMatches(string $patterns, string $host, int $port): bool
    {
        $candidates = [strtolower(self::hostField($host, $port))];
        if ($port !== 22) {
            // OpenSSH accepts a plain hostname entry for any port when no port-specific one exists
            $candidates[] = strtolower($host);
        }

        if (str_starts_with($patterns, '|1|')) {
            [, , $salt, $hash] = explode('|', $patterns, 4) + [null, null, '', ''];
            $saltRaw = base64_decode($salt, true);
            if ($saltRaw === false) {
                return false;
            }
            foreach ($candidates as $candidate) {
                if (hash_equals(base64_encode(hash_hmac('sha1', $candidate, $saltRaw, true)), $hash)) {
                    return true;
                }
            }

            return false;
        }

        $matched = false;
        foreach (explode(',', strtolower($patterns)) as $pattern) {
            $negate = str_starts_with($pattern, '!');
            $pattern = ltrim($pattern, '!');
            $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';
            foreach ($candidates as $candidate) {
                if (preg_match($regex, $candidate) === 1) {
                    if ($negate) {
                        return false;
                    }
                    $matched = true;
                }
            }
        }

        return $matched;
    }
}
