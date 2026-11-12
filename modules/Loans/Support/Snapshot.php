<?php

namespace Modules\Loans\Support;

/**
 * SHA-256 hash of a frozen document content. The content is encoded as json with sorted keys, so
 * the same content always gives the same hash.
 */
class Snapshot
{
    /**
     * Hash of the content.
     *
     * @param  array<string, mixed>  $content
     */
    public static function hash(array $content): string
    {
        return hash('sha256', json_encode(self::sorted($content), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Content with the keys of every level sorted.
     *
     * @param  array<mixed>  $content
     * @return array<mixed>
     */
    private static function sorted(array $content): array
    {
        if (! array_is_list($content)) {
            ksort($content);
        }

        return array_map(fn ($value) => is_array($value) ? self::sorted($value) : $value, $content);
    }
}
