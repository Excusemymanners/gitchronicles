<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the security headers of every response (security audit WEB-LAYER-06, XSS-INJECTION-004):
 *
 * - Content-Security-Policy: the page may load scripts only from this site and from the one inline script
 *   that carries the per-response nonce (the theme script in app.blade.php), so even a mistake in the
 *   Markdown or Mermaid sanitizers cannot run a script from the repository's text; no plugins, no <base>,
 *   forms go only to this site and no other site may frame the app;
 * - X-Content-Type-Options, Referrer-Policy, X-Frame-Options (old browsers) and, over HTTPS, HSTS;
 * - X-Powered-By (the PHP version) is removed.
 *
 * What the policy allows is exactly what the app uses: styles with inline attributes (React and Mermaid set
 * them), data: images (Mermaid, the logo), the avatar of a repository's owner from GitHub, video and poster
 * of the same site (and blob: for the player), fonts of the same site. While the Vite dev server runs
 * (the public/hot file exists) its scheme is added, and only then.
 */
class SecurityHeaders
{
    /**
     * The images of GitHub that the front door shows (the avatar of a repository's owner).
     */
    private const GITHUB_AVATARS = 'https://avatars.githubusercontent.com';

    /**
     * Handle the request: the nonce is created before the page is rendered, so the view can use it.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('Content-Security-Policy', $this->policy($nonce));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->remove('X-Powered-By');
        header_remove('X-Powered-By');

        if ($request->isSecure() && config('gitchronicle.http.hsts_seconds') > 0) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.config('gitchronicle.http.hsts_seconds'));
        }

        return $response;
    }

    /**
     * The Content-Security-Policy for one response.
     */
    public function policy(string $nonce): string
    {
        // Chrome rejects the address of a dev server written as an IPv6 literal ("http://[::1]:5173") in a policy, so
        // while the server runs the whole scheme is allowed ("http:", "ws:"), and only then.
        $dev = $this->devServerScheme();
        $devSocket = $dev === null ? '' : ' '.($dev === 'https:' ? 'wss:' : 'ws:');
        $dev = $dev === null ? '' : ' '.$dev;

        $directives = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$dev}",
            "style-src 'self' 'unsafe-inline'{$dev}",
            "img-src 'self' data: blob: ".self::GITHUB_AVATARS.$dev,
            "font-src 'self' data:{$dev}",
            "connect-src 'self'{$dev}{$devSocket}",
            "media-src 'self' blob:",
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];

        return implode('; ', $directives);
    }

    /**
     * The scheme of the Vite dev server ("http:" or "https:") while it runs (the public/hot file exists), otherwise null.
     */
    private function devServerScheme(): ?string
    {
        $hotFile = public_path('hot');

        if (! is_file($hotFile)) {
            return null;
        }

        return str_starts_with(trim((string) file_get_contents($hotFile)), 'https://') ? 'https:' : 'http:';
    }
}
