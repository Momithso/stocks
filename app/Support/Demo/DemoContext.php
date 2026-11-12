<?php

namespace App\Support\Demo;

use App\Models\Item;
use App\Models\ItemPhoto;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Shared state of one run of the demo generator: the random numbers of the seed, the acting
 * account, the models the parts tagged for later parts, and the labels of the test sheet.
 *
 * All random choices go through this class. The same seed gives the same choices, the same order
 * of creation and therefore the same item numbers.
 */
class DemoContext
{
    /**
     * Font of the generated photos (part of dompdf).
     */
    private const FONT = 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';

    public readonly Randomizer $random;

    /**
     * Start of today; dates of the demo data are relative to it, so there is always something to
     * pick up today and something overdue.
     */
    public readonly CarbonImmutable $today;

    /**
     * Models by tag, e.g. "compartment" or "box", for later parts.
     *
     * @var array<string, list<Model>>
     */
    private array $tags = [];

    /**
     * Labels of the test sheet in their order.
     *
     * @var list<array{code: string, name: string, purpose: string, expect: string}>
     */
    private array $labels = [];

    /**
     * False once S3 failed to store a photo; then no more photos are tried.
     */
    private bool $photosWork = true;

    public function __construct(public readonly int $seed, public readonly User $actor)
    {
        $this->random = new Randomizer(new Mt19937($seed));
        $this->today = CarbonImmutable::today();
    }

    /**
     * Random whole number between both values (both included).
     */
    public function int(int $min, int $max): int
    {
        return $this->random->getInt($min, $max);
    }

    /**
     * True with the given probability (0 to 1).
     */
    public function chance(float $probability): bool
    {
        return $this->random->getInt(1, 1000) <= $probability * 1000;
    }

    /**
     * One random element of a list.
     *
     * @template T
     *
     * @param  list<T>  $list
     * @return T
     */
    public function pick(array $list): mixed
    {
        return $list[$this->random->getInt(0, count($list) - 1)];
    }

    /**
     * A key of the map, chosen by its weight: ['gut' => 6, 'defekt' => 1].
     *
     * @param  array<string, int>  $weights
     */
    public function weighted(array $weights): string
    {
        $value = $this->random->getInt(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            $value -= $weight;
            if ($value <= 0) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
    }

    /**
     * The list in random order.
     *
     * @template T
     *
     * @param  list<T>  $list
     * @return list<T>
     */
    public function shuffle(array $list): array
    {
        return $this->random->shuffleArray($list);
    }

    /**
     * Remember a model under a tag for later parts.
     */
    public function tag(string $tag, Model $model): void
    {
        $this->tags[$tag][] = $model;
    }

    /**
     * Models of a tag in the order they were tagged.
     *
     * @return list<Model>
     */
    public function tagged(string $tag): array
    {
        return $this->tags[$tag] ?? [];
    }

    /**
     * Take a model of a tag that no other part took yet (it is removed from the tag), null if the
     * tag is empty.
     */
    public function take(string $tag): ?Model
    {
        return array_shift($this->tags[$tag]);
    }

    /**
     * Add a label to the test sheet.
     *
     * @param  string  $purpose  what the label is meant for, e.g. "Box, bestätigte Buchung: für die Ausgabe"
     * @param  string  $expect  what should happen when it is scanned
     * @param  string|null  $url  address in the code instead of the short link of $code (e.g. a borrower page)
     */
    public function label(string $code, string $name, string $purpose, string $expect, ?string $url = null): void
    {
        $this->labels[] = [...compact('code', 'name', 'purpose', 'expect'), ...($url ? ['url' => $url] : [])];
    }

    /**
     * @return list<array{code: string, name: string, purpose: string, expect: string, url?: string}>
     */
    public function labels(): array
    {
        return $this->labels;
    }

    /**
     * Give an item a generated photo: a coloured area with its name. Skipped (with all later
     * photos) when S3 is not reachable, the demo data work without photos.
     */
    public function photo(Item $item, string $text): void
    {
        if (! $this->photosWork) {
            return;
        }

        $image = imagecreatetruecolor(640, 480);
        // a colour from the seed, light enough for dark text
        imagefill($image, 0, 0, imagecolorallocate($image, $this->int(120, 230), $this->int(120, 230), $this->int(120, 230)));
        $ink = imagecolorallocate($image, 30, 30, 30);
        $lines = explode("\n", wordwrap($text, 18, "\n", true));
        foreach ($lines as $index => $line) {
            imagettftext($image, 34, 0, 40, 120 + $index * 60, $ink, base_path(self::FONT), $line);
        }
        imagettftext($image, 20, 0, 40, 440, $ink, base_path(self::FONT), $item->number);

        $path = tempnam(sys_get_temp_dir(), 'demo').'.png';
        imagepng($image, $path);

        try {
            ItemPhoto::uploadAll($item, [new UploadedFile($path, 'demo.png', 'image/png', null, true)]);
        } catch (\Throwable) {
            $this->photosWork = false;
        } finally {
            @unlink($path);
        }
    }
}
