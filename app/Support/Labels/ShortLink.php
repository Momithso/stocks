<?php

namespace App\Support\Labels;

use App\Support\Settings;

/**
 * Short links in the codes of labels, e.g. "https://stocks.example/s/FSR5-004218". Any phone camera
 * opens them; the scan in Stocks itself only reads the code at the end.
 */
class ShortLink
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Address of the instance on labels: the setting "labels.base_url", or APP_URL.
     */
    public function baseUrl(): string
    {
        return rtrim((string) ($this->settings->get('labels.base_url') ?: config('app.url')), '/');
    }

    /**
     * Short link of an item number or location code.
     */
    public function url(string $code): string
    {
        return $this->baseUrl().'/s/'.rawurlencode($code);
    }

    /**
     * Check if the current address was confirmed before printing. Changing it later breaks the
     * links of labels already printed, so the print dialog asks once.
     */
    public function isConfirmed(): bool
    {
        return $this->settings->get('labels.base_url_confirmed') === $this->baseUrl();
    }

    /**
     * Code of a scanned input: a short link of any address counts like the code at its end
     * ("https://x/s/FSR5-004218?a=1" → "FSR5-004218"); everything else is returned trimmed.
     */
    public static function codeOf(string $input): string
    {
        $input = trim($input);

        if (preg_match('~^https?://[^/]+(?:/.*)?/s/([^/?#]+)~i', $input, $matches)) {
            return rawurldecode($matches[1]);
        }

        return $input;
    }
}
