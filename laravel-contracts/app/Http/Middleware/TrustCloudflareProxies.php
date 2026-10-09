<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * TrustCloudflareProxies
 *
 * Configures Laravel to trust Cloudflare and the local tunnel proxy
 * so that:
 *   - request.isSecure() returns true (HTTPS from Cloudflare)
 *   - request.ip() returns the real client IP from CF-Connecting-IP
 *   - URL generation uses https://
 *
 * Cloudflare's IP ranges are published at:
 *   https://www.cloudflare.com/ips/
 *
 * Register in bootstrap/app.php:
 *   ->withMiddleware(function (Middleware $middleware) {
 *       $middleware->trustProxies(at: '*',
 *           headers: Request::HEADER_X_FORWARDED_FOR |
 *                    Request::HEADER_X_FORWARDED_PROTO |
 *                    Request::HEADER_X_FORWARDED_HOST);
 *   })
 *
 * Or use this middleware class in the HTTP kernel.
 */
class TrustCloudflareProxies
{
    /**
     * Cloudflare IPv4 and IPv6 ranges.
     * Loopback tunnel proxies are trusted separately in handle().
     */
    private const CLOUDFLARE_IPS = [
        // IPv4
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22',
        '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22',
        '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
        '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $remoteAddress = (string) $request->server->get('REMOTE_ADDR', '');
        $trustedProxies = array_merge(['127.0.0.0/8', '::1'], self::CLOUDFLARE_IPS);

        if (!IpUtils::checkIp($remoteAddress, $trustedProxies)) {
            return $next($request);
        }

        // Trust the CF-Connecting-IP header for real client IP
        $cfIp = $request->header('CF-Connecting-IP');
        if ($cfIp && filter_var($cfIp, FILTER_VALIDATE_IP)) {
            $request->server->set('REMOTE_ADDR', $cfIp);
        }

        // Trust X-Forwarded-Proto from Cloudflare
        $proto = $request->header('X-Forwarded-Proto');
        if ($proto === 'https') {
            $request->server->set('HTTPS', 'on');
        }

        return $next($request);
    }
}
