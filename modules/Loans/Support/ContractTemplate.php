<?php

namespace Modules\Loans\Support;

use App\Support\Settings;

/**
 * The contract text: the setting "loans.contract_template", or the text that comes with the module
 * (resources/contract.txt). The text is written in a small markup, one block per paragraph:
 *
 * - "## " heading, "### " sub heading
 * - "1. " numbered list item, "- " list item
 * - "**bold**" inside a line
 * - variables in braces, e.g. {borrower}; see VARIABLES
 *
 * The text is escaped before the markup is applied, so it can never contain html.
 */
class ContractTemplate
{
    /**
     * Variables and what they are replaced with.
     *
     * @var array<string, string>
     */
    public const VARIABLES = [
        'number' => 'Nummer der Buchung',
        'lender' => 'Verleiher aus den Einstellungen',
        'issuer' => 'Herausgeber aus dem FSR',
        'borrower' => 'Name des Entleihers',
        'responsible' => 'Name der verantwortlichen Person',
        'responsible_address' => 'ihre Anschrift',
        'responsible_phone' => 'ihre Telefonnummer',
        'responsible_email' => 'ihre E-Mail-Adresse',
        'pickup' => 'Abholung (Datum und Uhrzeit)',
        'return' => 'Rückgabe (Datum und Uhrzeit)',
        'purpose' => 'Anlass der Buchung',
        'contact' => 'Kontaktadresse aus den Einstellungen',
        'privacy_url' => 'Datenschutzerklärung aus den Einstellungen',
        'place' => 'Ort aus den Einstellungen',
        'date' => 'Datum der Unterschrift',
    ];

    /**
     * Variables kept on one line.
     *
     * @var list<string>
     */
    private const UNBROKEN = ['number', 'pickup', 'return', 'date', 'responsible_phone'];

    public function __construct(private readonly Settings $settings) {}

    /**
     * The text that comes with the module.
     */
    public static function default(): string
    {
        return (string) file_get_contents(dirname(__DIR__).'/resources/contract.txt');
    }

    /**
     * The current text: the setting or the default.
     */
    public function text(): string
    {
        return $this->settings->get('loans.contract_template') ?: self::default();
    }

    /**
     * The text as html with the variables filled in. Unknown variables stay as they are.
     *
     * @param  array<string, string|null>  $values
     */
    public static function render(string $text, array $values): string
    {
        $html = [];
        $list = null;

        foreach (preg_split('/\R\s*\R/', trim($text)) as $block) {
            foreach (preg_split('/\R/', trim($block)) as $line) {
                $line = self::inline(trim($line), $values);
                $tag = match (true) {
                    preg_match('/^(\d+)\.\s+(.*)$/', $line, $match) === 1 => 'ol',
                    preg_match('/^[-*]\s+(.*)$/', $line, $match) === 1 => 'ul',
                    default => null,
                };

                if ($tag !== $list) {
                    if ($list !== null) {
                        $html[] = "</{$list}>";
                    }
                    if ($tag !== null) {
                        $html[] = "<{$tag}>";
                    }
                    $list = $tag;
                }

                $html[] = match (true) {
                    $tag !== null => '<li>'.end($match).'</li>',
                    str_starts_with($line, '### ') => '<h3>'.substr($line, 4).'</h3>',
                    str_starts_with($line, '## ') => '<h2>'.substr($line, 3).'</h2>',
                    default => "<p>{$line}</p>",
                };
            }
        }
        if ($list !== null) {
            $html[] = "</{$list}>";
        }

        return implode("\n", $html);
    }

    /**
     * Escape a line, fill in the variables and make "**text**" bold.
     *
     * @param  array<string, string|null>  $values
     */
    private static function inline(string $line, array $values): string
    {
        $line = e($line);
        $line = preg_replace_callback('/\{([a-z_]+)\}/', function (array $match) use ($values) {
            if (! array_key_exists($match[1], $values)) {
                return $match[0];
            }
            $value = e((string) ($values[$match[1]] ?: '–'));

            // numbers, dates and times never break inside
            return in_array($match[1], self::UNBROKEN, true) ? "<span class=\"nowrap\">{$value}</span>" : $value;
        }, $line);

        return preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $line);
    }
}
