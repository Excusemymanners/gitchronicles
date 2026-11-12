<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Downloads one file whose address came from a repository (the README's logo, an avatar).
 *
 * The address is written by a stranger, so asking for it from this server is dangerous: it could name an
 * address inside our own network (http://169.254.169.254/, https://localhost:8000/, a router) and the answer
 * would be saved and shown. So only https addresses of an allow-list of hosts are fetched, a redirect is
 * followed only to another allowed address, the body is read as it comes and cut off at a size limit (an endless
 * answer fills nothing), the time is limited, and the type the server declares must be an image.
 */
final class SafeDownload
{
    /**
     * How many redirects are followed (each one is checked like the first address).
     */
    private const MAX_REDIRECTS = 3;

    /**
     * What a server may declare for a file we accept as an image; the real type is checked from the bytes by the caller.
     */
    private const IMAGE_TYPES = '#\A(image/[a-z0-9.+-]+|application/octet-stream|binary/octet-stream|text/plain)\b#i';

    /**
     * The bytes of an image at an address, or null when the address is not allowed, the server does not answer,
     * the answer is not an image or it is larger than $maxBytes.
     *
     * @param  list<string>  $allowedHosts  host names; "*.example.com" allows every subdomain of example.com
     */
    public static function image(string $url, array $allowedHosts, int $maxBytes, int $timeoutSeconds = 10): ?string
    {
        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            if (! self::isAllowed($url, $allowedHosts)) {
                Log::info('A download was refused: the address is not on the allow-list.', ['host' => parse_url($url, PHP_URL_HOST)]);

                return null;
            }

            try {
                $response = Http::withHeaders(['User-Agent' => 'GitChronicle'])
                    ->withOptions(['allow_redirects' => false, 'stream' => true])
                    ->connectTimeout(5)
                    ->timeout($timeoutSeconds)
                    ->get($url);
            } catch (ConnectionException) {
                return null;
            }

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $url = self::resolve($url, $response->header('Location'));

                continue;
            }

            if (! $response->successful() || preg_match(self::IMAGE_TYPES, $response->header('Content-Type')) !== 1) {
                return null;
            }

            return self::readUpTo($response->toPsrResponse()->getBody(), $maxBytes);
        }

        return null;
    }

    /**
     * Whether an address may be fetched: https, no user name, the default port, and a host of the allow-list
     * (a number such as 127.0.0.1 or 169.254.169.254 is never on it).
     *
     * @param  list<string>  $allowedHosts
     */
    public static function isAllowed(string $url, array $allowedHosts): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || ($parts['port'] ?? 443) !== 443 || ! isset($parts['host'])) {
            return false;
        }

        $host = strtolower(rtrim($parts['host'], '.'));

        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower($allowed);

            if ($host === $allowed || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The address a Location header points to, as an absolute address.
     */
    private static function resolve(string $from, string $location): string
    {
        if (preg_match('#\A[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($from);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        return str_starts_with($location, '/') ? $origin.$location : $origin.rtrim(dirname($parts['path'] ?? '/'), '/').'/'.$location;
    }

    /**
     * Read a body in pieces and stop at the limit; null when the body is longer.
     */
    private static function readUpTo(mixed $body, int $maxBytes): ?string
    {
        try {
            $data = '';

            while (! $body->eof()) {
                $data .= $body->read(8192);

                if (strlen($data) > $maxBytes) {
                    return null;
                }
            }

            return $data;
        } catch (Throwable) {
            return null;
        }
    }
}
