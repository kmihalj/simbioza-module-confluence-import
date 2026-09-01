<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Support;

use function is_array;
use function is_string;
use function ord;
use function parse_url;
use function preg_replace_callback;
use function rawurldecode;
use function sprintf;

use const PHP_URL_FRAGMENT;
use const PHP_URL_HOST;
use const PHP_URL_PATH;
use const PHP_URL_QUERY;
use const PHP_URL_SCHEME;

/** HR: Čita URL bez oštećivanja valjanih UTF-8 znakova. EN: Parses URLs without corrupting valid UTF-8 characters. */
final class Utf8Url
{
    /**
     * HR: Vraća URL dijelove i čuva sirove Unicode znakove te postojeće percent-encoded sekvence.
     * EN: Returns URL parts while preserving raw Unicode characters and existing percent-encoded sequences.
     *
     * @return array<string,int|string>|null
     */
    public static function parts(string $url): ?array
    {
        // HR: PHP-ov parse_url može promijeniti UTF-8 continuation bajtove koji
        //     odgovaraju kontrolnim znakovima. Postojeći znak % prvo štitimo,
        //     a sirove non-ASCII bajtove privremeno percent-encodeamo.
        // EN: PHP's parse_url can alter UTF-8 continuation bytes that match
        //     control characters. Protect existing percent signs first, then
        //     temporarily percent-encode raw non-ASCII bytes.
        $protected = preg_replace_callback(
            '/%|[\x80-\xFF]/',
            static fn (array $match): string => $match[0] === '%'
                ? '%25'
                : sprintf('%%%02X', ord($match[0])),
            $url,
        );
        if (!is_string($protected)) {
            return null;
        }

        $parts = parse_url($protected);
        if (!is_array($parts)) {
            return null;
        }

        foreach ($parts as $name => $value) {
            if (is_string($value)) {
                $parts[$name] = rawurldecode($value);
            }
        }

        return $parts;
    }

    /** HR: Vraća tekstni dio URL-a. EN: Returns a textual URL component. */
    public static function component(string $url, int $component): ?string
    {
        $key = match ($component) {
            PHP_URL_SCHEME => 'scheme',
            PHP_URL_HOST => 'host',
            PHP_URL_PATH => 'path',
            PHP_URL_QUERY => 'query',
            PHP_URL_FRAGMENT => 'fragment',
            default => null,
        };
        if ($key === null) {
            return null;
        }

        $parts = self::parts($url);
        $value = $parts[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
