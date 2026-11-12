<?php

namespace App\Support\Demo;

use App\Actions\Inventory\ChangeItemCondition;
use App\Actions\Inventory\CreateItem;
use App\Actions\Inventory\MoveItem;
use App\Actions\Inventory\RetireItem;
use App\Actions\Inventory\SaveLocation;
use App\Actions\Labels\ReserveNumbers;
use App\Enums\RetireReason;
use App\Models\Category;
use App\Models\Item;
use App\Models\ItemCondition;
use App\Models\Location;
use App\Support\Inventory\ItemNumbers;

/**
 * Demo inventory of the core: 60 locations on all four levels, categories, 30 boxes and about 400
 * items, built from short lists of building blocks. First the required cases (every variant the
 * inventory knows), then random items until the total is reached. See documentation/DEMO.md.
 *
 * Tags for later parts: "compartment", "box" (boxes on a shelf, not on the test sheet),
 * "lendable" (single pieces at their home), "lendable_bulk" (bulk items at their home that are not
 * consumables), "consumable".
 */
class InventoryDemo implements DemoPart
{
    /**
     * Items in total, boxes and split off parts included.
     */
    public const ITEMS = 400;

    /**
     * Boxes in total.
     */
    public const BOXES = 30;

    /**
     * Addresses with their rooms (code => name).
     */
    private const ROOMS = [
        'EU' => ['name' => 'Eupener Straße', 'rooms' => ['014' => 'Lager', '015' => 'Technikraum', '102' => 'FSR-Büro']],
        'BA' => ['name' => 'Bayernallee', 'rooms' => ['001' => 'Keller', '012' => 'Lager', '020' => 'Küche']],
    ];

    /**
     * Building blocks of single pieces: category => [kinds of devices, makers].
     */
    private const SINGLES = [
        'Audio' => [['Lautsprecher', 'Aktivbox', 'Subwoofer', 'Mischpult', 'Funkmikrofon', 'Mikrofon', 'Endstufe', 'In-Ear-Sender'], ['Yamaha', 'Shure', 'Behringer', 'LD Systems', 'Sennheiser', 'JBL', 'the box']],
        'Licht' => [['LED-Scheinwerfer', 'Moving Head', 'Nebelmaschine', 'Lichtpult', 'Stroboskop', 'Lichtleiste'], ['Stairville', 'Eurolite', 'Cameo', 'Showtec']],
        'Video' => [['Beamer', 'Leinwand', 'Laptop', 'Kamera', 'Monitor', 'HDMI-Splitter'], ['Epson', 'BenQ', 'Lenovo', 'Sony', 'Dell']],
        'Möbel' => [['Stehtisch', 'Pavillon 3 × 3 m', 'Klapptisch', 'Rednerpult', 'Garderobenständer'], ['Kopp', 'Rexel', 'Bremshey']],
        'Spiele' => [['Kicker', 'Wikingerschach', 'Tischtennisplatte', 'Riesen-Jenga', 'Bierpong-Tisch'], ['Ullrich', 'Kettler', 'Spielwerk']],
        'Werkzeug' => [['Akkuschrauber', 'Werkzeugkoffer', 'Leiter', 'Kabeltrommel', 'Heißklebepistole'], ['Bosch', 'Makita', 'Krause', 'Brennenstuhl']],
    ];

    /**
     * Building blocks of bulk items: category => [names, unit, smallest and largest quantity].
     */
    private const BULKS = [
        'Kabel' => [['XLR-Kabel', 'Klinkenkabel', 'HDMI-Kabel', 'Kaltgerätekabel', 'Verlängerungskabel', 'Mehrfachsteckdose', 'DMX-Kabel'], 'Stück', 4, 30],
        'Möbel' => [['Bierzeltgarnitur', 'Stuhl', 'Bierbank'], 'Sets', 4, 40],
        'Deko' => [['Lichterkette', 'Tischdecke', 'Girlande', 'Fahne'], 'Stück', 3, 20],
        'Küche' => [['Becher', 'Teller', 'Besteck-Set', 'Schüssel', 'Thermoskanne', 'Kühlbox'], 'Stück', 5, 120],
    ];

    /**
     * Building blocks of consumables: [names, unit, smallest and largest quantity].
     */
    private const CONSUMABLES = [
        [['Gaffa-Tape', 'Panzertape', 'Malerkrepp'], 'Rollen', 3, 24],
        [['Kabelbinder', 'Batterien AA', 'Batterien AAA', 'Müllsäcke', 'Servietten'], 'Packungen', 2, 15],
    ];

    /**
     * Variants added to names: lengths of cables, sizes, numbers.
     */
    private const LENGTHS = ['1 m', '3 m', '5 m', '10 m', '20 m'];

    /**
     * Names of boxes.
     */
    private const BOX_NAMES = ['Kabelkiste', 'Technikkoffer', 'Partybox', 'Werkzeugkiste', 'Lichtkoffer', 'Mikrofonkoffer', 'Deko-Kiste', 'Küchenbox', 'Spielekiste', 'Erste-Hilfe-Box'];

    /**
     * Created categories by name.
     *
     * @var array<string, Category>
     */
    private array $categories = [];

    /**
     * Conditions by name.
     *
     * @var array<string, ItemCondition>
     */
    private array $conditions = [];

    /**
     * Compartments and shelves items are put in.
     *
     * @var list<Location>
     */
    private array $places = [];

    /**
     * Boxes items are put in.
     *
     * @var list<Item>
     */
    private array $boxes = [];

    private int $created = 0;

    public function __construct(
        private readonly SaveLocation $saveLocation,
        private readonly CreateItem $createItem,
        private readonly MoveItem $moveItem,
        private readonly RetireItem $retireItem,
        private readonly ChangeItemCondition $changeCondition,
        private readonly ReserveNumbers $reserveNumbers,
        private readonly ItemNumbers $numbers,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function run(DemoContext $demo): void
    {
        $this->conditions = ItemCondition::all()->keyBy('name')->all();
        $this->createLocations($demo);
        $this->createCategories();
        $this->createBoxes($demo);
        $this->createRequiredCases($demo);

        while ($this->created < self::ITEMS) {
            $this->createRandomItem($demo);
        }

        $this->markLabels($demo);
    }

    /**
     * Two addresses, six rooms, sixteen shelves and 36 compartments.
     */
    private function createLocations(DemoContext $demo): void
    {
        $shelfIndex = 0;
        foreach (self::ROOMS as $addressCode => $address) {
            $addressLocation = $this->saveLocation->handle(null, ['parent_id' => null, 'code' => $addressCode, 'name' => $address['name']]);
            foreach ($address['rooms'] as $roomCode => $roomName) {
                $room = $this->saveLocation->handle(null, ['parent_id' => $addressLocation->id, 'code' => (string) $roomCode, 'name' => $roomName]);
                // 16 shelves on 6 rooms: two rooms get two, the others three
                $shelves = in_array($roomName, ['FSR-Büro', 'Küche'], true) ? 2 : 3;
                for ($number = 1; $number <= $shelves; $number++) {
                    $shelf = $this->saveLocation->handle(null, ['parent_id' => $room->id, 'code' => sprintf('R%02d', $number), 'name' => $demo->pick(['Schwerlastregal', 'Steckregal', 'Schrank', 'Gitterbox'])]);
                    $this->places[] = $shelf;
                    // 36 compartments on 16 shelves: the first four shelves get three
                    $compartments = $shelfIndex++ < 4 ? 3 : 2;
                    for ($level = 1; $level <= $compartments; $level++) {
                        $compartment = $this->saveLocation->handle(null, ['parent_id' => $shelf->id, 'code' => (string) $level]);
                        $this->places[] = $compartment;
                        $demo->tag('compartment', $compartment);
                    }
                }
            }
        }
    }

    /**
     * Categories with sub categories.
     */
    private function createCategories(): void
    {
        foreach (['Technik' => ['Audio', 'Licht', 'Video', 'Kabel'], 'Party' => ['Möbel', 'Deko', 'Küche'], 'Freizeit' => ['Spiele'], 'Material' => ['Werkzeug', 'Verbrauch']] as $parent => $children) {
            $parentCategory = Category::create(['name' => $parent]);
            $this->categories[$parent] = $parentCategory;
            foreach ($children as $child) {
                $this->categories[$child] = Category::create(['name' => $child, 'parent_id' => $parentCategory->id]);
            }
        }
        $this->categories['Boxen'] = Category::create(['name' => 'Boxen']);
    }

    /**
     * 30 boxes in compartments; the last three lie in other boxes (box in box).
     */
    private function createBoxes(DemoContext $demo): void
    {
        for ($index = 0; $index < self::BOXES; $index++) {
            $name = self::BOX_NAMES[$index % count(self::BOX_NAMES)].' '.(intdiv($index, count(self::BOX_NAMES)) + 1);
            $inner = $index >= self::BOXES - 3;
            $place = $inner ? $this->boxes[$index - 20] : $demo->pick($this->places);
            $box = $this->create($demo, ['name' => $name, 'kind' => 'single', 'is_container' => true, 'category_id' => $this->categories['Boxen']->id], 'gut', $place);
            $this->boxes[] = $box;
            // the first two boxes are on the test sheet as they are, later parts do not lend them
            $demo->tag($inner ? 'inner_box' : ($index < 2 ? 'sheet_box' : 'box'), $box);
        }
    }

    /**
     * Every variant of the inventory at least twice, the first one of each on the test sheet.
     */
    private function createRequiredCases(DemoContext $demo): void
    {
        [$first, $second] = $demo->tagged('compartment');
        $demo->label($first->full_code, $first->full_code, 'Fach', 'Öffnet das Fach mit seinen Gegenständen; im Scan-Tab „Hierher umlagern“.');
        $demo->label($second->full_code, $second->full_code, 'Fach, Ziel zum Umlagern', 'Als zweiter Scan beim Umlagern: der Gegenstand liegt danach hier.');

        for ($round = 0; $round < 2; $round++) {
            $single = $this->createSingle($demo, 'Audio', $first);
            $bulk = $this->createBulk($demo, 'Kabel', $first);
            $consumable = $this->createConsumable($demo, $second);
            $defect = $this->createSingle($demo, 'Licht', $second);
            $this->changeCondition->handle($demo->actor, $defect, $this->conditions['defekt'], 'Lüfter läuft nicht mehr an.');
            $retired = $this->createSingle($demo, 'Video', $second);
            $this->retireItem->handle($demo->actor, $retired, RetireReason::Defective, 'Display gebrochen.');
            $hint = $this->createSingle($demo, 'Video', $first, 'gut', ['label_hint' => 'Achtung, zerbrechlich']);
            $demo->photo($hint, $hint->name);
            $demo->photo($hint, 'Zubehör');
            $this->createSingle($demo, 'Werkzeug', null);
            // a part of a bulk item, split off by moving it into a box
            $origin = $this->createBulk($demo, 'Möbel', $second, 20);
            $part = $this->moveItem->handle($demo->actor, $origin, $this->boxes[0], 5, 'Für die Partybox abgeteilt.');
            $this->created++;

            if ($round === 0) {
                $demo->label($single->number, $single->name, 'Einzelstück', 'Karte mit Umlagern, Zustand, Etikett.');
                $demo->label($bulk->number, $bulk->name, 'Mengenartikel', 'Karte mit Menge; Umlagern einer Teilmenge teilt ab.');
                $demo->label($consumable->number, $consumable->name, 'Verbrauchsmaterial', 'Karte mit Knopf „Verbraucht“.');
                $demo->label($defect->number, $defect->name, 'Defekter Gegenstand', 'Zustand rot; nicht verleihbar.');
                $demo->label($retired->number, $retired->name, 'Ausgemustert', 'Karte „ausgemustert“, keine Aktionen.');
                $demo->label($part->number, $part->name, 'Abgeteilter Teil', 'Karte mit „Zurückbuchen“ zum Ursprung.');
                $demo->label($hint->number, $hint->name, 'Mit Hinweis und Fotos', 'Karte mit Foto; Etikett trägt „Achtung, zerbrechlich“.');
            }
        }

        // boxes with contents, an inner box
        $box = $this->boxes[1];
        foreach (['Audio', 'Kabel', 'Video'] as $category) {
            $category === 'Kabel' ? $this->createBulk($demo, $category, $box) : $this->createSingle($demo, $category, $box);
        }
        $innerBox = $demo->tagged('inner_box')[0];
        $this->createSingle($demo, 'Werkzeug', $innerBox);
        $demo->label($box->number, $box->name, 'Box mit Inhalt', 'Öffnet die Box; ohne Anmeldung die Inhaltsliste zum Abhaken.');
        $demo->label($innerBox->number, $innerBox->name, 'Box in einer Box', "Liegt in {$innerBox->container->number}; der Inhalt reist mit.");

        // stock labels: reserved numbers without item yet, and a number nobody has
        $reserved = $this->reserveNumbers->handle($demo->actor, 6);
        $demo->label($reserved->first()->number, 'Etikett auf Vorrat', 'Reservierte Nummer', 'Bietet „Gegenstand anlegen“ mit dieser Nummer an.');
        $digits = '99999';
        $unknown = $this->numbers->prefix().'-'.$digits.ItemNumbers::checkDigit($digits);
        $demo->label($unknown, 'Unbekannt', 'Unbekannte Nummer', 'Meldung „Zu … gibt es nichts.“');
    }

    /**
     * One random item: two of five are single pieces, two bulk items, one a consumable. A third
     * lies in a box, a few lie away from their home or have no place.
     */
    private function createRandomItem(DemoContext $demo): void
    {
        $place = $demo->chance(0.3) ? $demo->pick($this->boxes) : $demo->pick($this->places);
        $home = $place;
        if ($demo->chance(0.04)) {
            $place = $demo->pick($this->places);
        } elseif ($demo->chance(0.02)) {
            $place = null;
        }
        $condition = $demo->weighted(['neu' => 2, 'gut' => 10, 'gebraucht' => 5, 'defekt' => 1]);

        $kind = $demo->weighted(['single' => 2, 'bulk' => 2, 'consumable' => 1]);
        $item = match ($kind) {
            'single' => $this->createSingle($demo, $demo->pick(array_keys(self::SINGLES)), $place, $condition, home: $home),
            'bulk' => $this->createBulk($demo, $demo->pick(array_keys(self::BULKS)), $place, null, $condition, $home),
            default => $this->createConsumable($demo, $place, $home),
        };

        // later parts lend items that lie on a shelf at their home and are not defective
        if ($place instanceof Location && $place === $home && $condition !== 'defekt') {
            $demo->tag(['single' => 'lendable', 'bulk' => 'lendable_bulk', 'consumable' => 'consumable'][$kind], $item);
        }

        if ($demo->chance(0.04)) {
            $demo->photo($item, $item->name);
        }
    }

    /**
     * A single piece of a category, e.g. "Lautsprecher Yamaha DXR 12".
     *
     * @param  array<string, mixed>  $extra
     */
    private function createSingle(DemoContext $demo, string $category, Location|Item|null $place, string $condition = 'gut', array $extra = [], Location|Item|null|false $home = false): Item
    {
        [$kinds, $makers] = self::SINGLES[$category];
        $name = $demo->pick($kinds).' '.$demo->pick($makers).' '.$demo->pick(['', 'Pro ', 'Mini ', 'X', 'S']).$demo->int(1, 20);

        return $this->create($demo, [...$extra, 'name' => trim($name), 'kind' => 'single', 'category_id' => $this->categories[$category]->id], $condition, $place, $home);
    }

    /**
     * A bulk item of a category, e.g. "XLR-Kabel 5 m".
     */
    private function createBulk(DemoContext $demo, string $category, Location|Item|null $place, ?int $quantity = null, string $condition = 'gut', Location|Item|null|false $home = false): Item
    {
        [$names, $unit, $min, $max] = self::BULKS[$category];
        $name = $demo->pick($names).($category === 'Kabel' ? ' '.$demo->pick(self::LENGTHS) : '');

        return $this->create($demo, ['name' => $name, 'kind' => 'bulk', 'quantity' => $quantity ?? $demo->int($min, $max), 'unit' => $unit, 'category_id' => $this->categories[$category]->id], $condition, $place, $home);
    }

    /**
     * A consumable (unit "Rollen" or "Packungen").
     */
    private function createConsumable(DemoContext $demo, Location|Item|null $place, Location|Item|null|false $home = false): Item
    {
        [$names, $unit, $min, $max] = $demo->pick(self::CONSUMABLES);

        return $this->create($demo, ['name' => $demo->pick($names), 'kind' => 'bulk', 'quantity' => $demo->int($min, $max), 'unit' => $unit, 'category_id' => $this->categories['Verbrauch']->id], 'neu', $place, $home);
    }

    /**
     * Create an item through the action, so number and movement log are real.
     *
     * @param  array<string, mixed>  $data
     * @param  Location|Item|null|false  $home  false: the same as the place
     */
    private function create(DemoContext $demo, array $data, string $condition, Location|Item|null $place, Location|Item|null|false $home = false): Item
    {
        $this->created++;

        return $this->createItem->handle($demo->actor, $data, $this->conditions[$condition], $place, $home === false ? $place : $home);
    }

    /**
     * Most items have a printed label; one in ten has none yet ("Noch ohne Etikett"). Written as
     * query like the print itself, so neither audit nor updated_at change.
     */
    private function markLabels(DemoContext $demo): void
    {
        $unlabeled = [];
        foreach (Item::orderBy('id')->pluck('id') as $id) {
            if ($demo->chance(0.1)) {
                $unlabeled[] = $id;
            }
        }

        Item::whereNotIn('id', $unlabeled)->toBase()->update(['label_printed_at' => $demo->today->subDays(3)]);
        Location::query()->toBase()->update(['label_printed_at' => $demo->today->subDays(3)]);
    }
}
