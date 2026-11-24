<?php

namespace App\Support;

/**
 * Strict, security-hardened YouTube URL parser and safe external URL validator.
 * Prevents XSS (javascript:/data:), arbitrary iframe injection, and SSRF to private hosts.
 */
final class YouTubeUrlParser
{
    private const ALLOWED_HOSTS = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'youtu.be',
        'www.youtu.be',
        'youtube-nocookie.com',
        'www.youtube-nocookie.com',
    ];

    /**
     * Parse any standard YouTube link into strict canonical metadata, or return null if invalid.
     *
     * @return array{youtubeId: string, youtubeUrl: string, embedUrl: string, thumbnailUrl: string}|null
     */
    public static function parse(?string $rawUrl): ?array
    {
        $url = trim((string) $rawUrl);
        if ($url === '' || strlen($url) > 500) {
            return null;
        }

        // Allow bare 11-char YouTube IDs if pasted directly? Only full http(s) URLs or strict 11-char IDs:
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url) === 1) {
            return self::buildPayload($url);
        }

        $parsed = parse_url($url);
        if (! is_array($parsed)) {
            return null;
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) ($parsed['host'] ?? ''));
        if (! in_array($host, self::ALLOWED_HOSTS, true)) {
            return null;
        }

        $path = trim((string) ($parsed['path'] ?? ''), '/');
        $segments = $path === '' ? [] : explode('/', $path);
        $videoId = null;

        if ($host === 'youtu.be' || $host === 'www.youtu.be') {
            $videoId = $segments[0] ?? null;
        } else {
            // /watch?v=VIDEO_ID
            if (isset($parsed['query'])) {
                parse_str($parsed['query'], $queryParams);
                if (isset($queryParams['v']) && is_string($queryParams['v'])) {
                    $videoId = $queryParams['v'];
                }
            }

            // /embed/{id}, /shorts/{id}, /live/{id}, /v/{id}
            if ($videoId === null && count($segments) >= 2 && in_array($segments[0], ['embed', 'shorts', 'live', 'v'], true)) {
                $videoId = $segments[1];
            }
        }

        if (! is_string($videoId) || preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) !== 1) {
            return null;
        }

        return self::buildPayload($videoId);
    }

    /**
     * Validate a safe external http(s) URL (for textbooks, PDFs, or educational resources),
     * blocking XSS protocols and local/private SSRF targets.
     */
    public static function isSafeExternalUrl(?string $rawUrl): bool
    {
        $url = trim((string) $rawUrl);
        if ($url === '' || strlen($url) > 500) {
            return false;
        }

        $parsed = parse_url($url);
        if (! is_array($parsed)) {
            return false;
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) ($parsed['host'] ?? ''));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            return false;
        }

        if (str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }

        // Reject raw IPv4/IPv6 in private or reserved ranges
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{youtubeId: string, youtubeUrl: string, embedUrl: string, thumbnailUrl: string}
     */
    private static function buildPayload(string $videoId): array
    {
        return [
            'youtubeId' => $videoId,
            'youtubeUrl' => "https://www.youtube.com/watch?v={$videoId}",
            'embedUrl' => "https://www.youtube-nocookie.com/embed/{$videoId}",
            'thumbnailUrl' => "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
        ];
    }
}
