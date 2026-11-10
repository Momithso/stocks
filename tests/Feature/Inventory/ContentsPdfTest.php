<?php

namespace Tests\Feature\Inventory;

use App\Http\Controllers\Inventory\ContentsPdfController;
use App\Models\Item;
use App\Models\ItemPhoto;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Contents of a box as pdf.
 */
class ContentsPdfTest extends TestCase
{
    public function test_the_contents_of_a_box_download_as_pdf(): void
    {
        $box = Item::factory()->container()->create(['name' => 'Bar']);
        Item::factory()->bulk(20, 'Stück')->at($box)->create(['name' => 'Becher']);

        $response = $this->actingAs($this->userWithRoles('Freies Mitglied'))->get("/inventory/items/{$box->id}/contents.pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_only_boxes_have_a_contents_list(): void
    {
        $item = Item::factory()->create();

        $this->actingAs($this->admin())->get("/inventory/items/{$item->id}/contents.pdf")->assertNotFound();
    }

    public function test_the_list_shows_address_description_notes_consumables_and_optionally_photos(): void
    {
        Storage::fake(ItemPhoto::DISK);
        app(Settings::class)->set(['organization.address' => "Eupener Straße 70\n52066 Aachen"]);
        $box = Item::factory()->container()->create();
        $tape = Item::factory()->bulk(3, 'Rollen')->at($box)->create(['description' => 'Schwarz, 50 mm', 'notes' => 'Nicht auf Lack']);
        ItemPhoto::uploadAll($tape, [UploadedFile::fake()->image('tape.jpg')]);
        $member = $this->userWithRoles('Freies Mitglied');

        $html = view('pdf.box-contents', [...$this->viewData($box, true), 'boxPhoto' => 'data:image/jpeg;base64,Qk9Y'])->render();
        $this->assertStringContainsString('data:image/jpeg;base64,Qk9Y', $html);
        foreach (['Eupener Straße 70', '52066 Aachen', 'Schwarz, 50 mm', 'Nicht auf Lack', 'Verbrauchsmaterial', 'Inhaltsliste digital'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $this->actingAs($member)->get("/inventory/items/{$box->id}/contents.pdf?photos=1")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    /**
     * Data of the view as the controller builds it, for checking the text without parsing the pdf.
     *
     * @return array<string, mixed>
     */
    private function viewData(Item $box, bool $withPhotos): array
    {
        return [
            'box' => $box,
            'rows' => $box->contents()->get()->map(fn (Item $item) => ['item' => $item, 'depth' => 0, 'photo' => null])->all(),
            'withPhotos' => $withPhotos,
            'boxPhoto' => null,
            'letter' => true,
            'recipient' => ['FSR', 'Eupener Straße 70', '52066 Aachen'],
            'returnLine' => 'FSR · Eupener Straße 70 · 52066 Aachen',
            'returnLineSize' => 6.0,
            'addressSize' => 10.0,
            'consumableUnits' => ['Rollen', 'Packungen'],
            'logo' => null,
            'qr' => '',
            'organization' => 'FSR',
            'address' => ['Eupener Straße 70', '52066 Aachen'],
            'contact' => null,
        ];
    }

    public function test_a_long_address_is_shortened_in_the_return_line(): void
    {
        $address = "Fachschaftsrat des Fachbereichs Elektrotechnik und Informationstechnik\nGebäude W, Raum 1.23, Zugang über den Innenhof\nEupener Straße 70\n52066 Aachen";
        app(Settings::class)->set(['organization.name' => 'Fachschaftsrat Elektrotechnik', 'organization.address' => $address]);
        $box = Item::factory()->container()->create();

        $controller = new \ReflectionMethod(ContentsPdfController::class, 'returnLine');
        $metrics = Pdf::setPaper('a4')->getDomPDF()->getFontMetrics();
        [$line, $size] = $controller->invoke(app(ContentsPdfController::class), $metrics, ['Fachschaftsrat Elektrotechnik', ...explode("\n", $address)]);

        $this->assertSame('Fachschaftsrat Elektrotechnik · Eupener Straße 70 · 52066 Aachen', $line);
        $this->assertGreaterThanOrEqual(5.0, $size);
        $this->actingAs($this->admin())->get("/inventory/items/{$box->id}/contents.pdf")->assertOk();
    }

    public function test_the_letter_is_optional_and_takes_a_recipient(): void
    {
        $box = Item::factory()->container()->create();
        $data = [...$this->viewData($box, false), 'letter' => false];

        $this->assertStringNotContainsString('Turmstraße 3', view('pdf.box-contents', $data)->render());

        $html = view('pdf.box-contents', [...$data, 'letter' => true, 'recipient' => ['Mensa Academica', 'Turmstraße 3', '52072 Aachen']])->render();
        $this->assertStringContainsString('Mensa Academica', $html);
        $this->assertStringNotContainsString('Inhaltsliste einer Box', $html);

        $this->actingAs($this->admin())
            ->get("/inventory/items/{$box->id}/contents.pdf?letter=1&recipient=".urlencode("Mensa Academica\nTurmstraße 3\n52072 Aachen"))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
