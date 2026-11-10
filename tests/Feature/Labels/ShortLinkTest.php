<?php

namespace Tests\Feature\Labels;

use App\Actions\Labels\ReserveNumbers;
use App\Models\Item;
use App\Models\ItemPhoto;
use App\Models\Location;
use App\Support\Labels\ShortLink;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Short links in the codes of labels (/s/{code}) and the public view.
 */
class ShortLinkTest extends TestCase
{
    public function test_members_are_sent_to_the_page_of_the_scanned_code(): void
    {
        $member = $this->userWithRoles('Freies Mitglied');
        $item = Item::factory()->create();
        $location = Location::factory()->create(['code' => 'R03', 'full_code' => 'R03']);

        $this->actingAs($member)->get("/s/{$item->number}")->assertRedirect("/inventory/items/{$item->id}");
        $this->actingAs($member)->get('/s/r03')->assertRedirect("/inventory/locations/{$location->id}");
    }

    public function test_guests_see_name_number_photo_and_owner_of_an_item(): void
    {
        Storage::fake(ItemPhoto::DISK);
        app(Settings::class)->set(['organization.name' => 'FSR Informatik', 'legal.contact_email' => 'fsr@example.org']);
        $item = Item::factory()->create(['name' => 'Beamer']);
        ItemPhoto::uploadAll($item, [UploadedFile::fake()->image('beamer.jpg')]);

        $response = $this->get("/s/{$item->number}")
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn ($page) => $page->component('Public/Show')
                ->where('kind', 'item')
                ->where('item.name', 'Beamer')
                ->where('owner', 'FSR Informatik')
                ->where('contact', 'fsr@example.org')
                ->missing('item.place'));

        // the photo link is signed and works without login
        $this->get($response->viewData('page')['props']['item']['photo_url'])->assertOk();
        $this->get("/s-photos/{$item->photos()->value('id')}")->assertForbidden();
    }

    public function test_a_box_shows_its_contents_only_when_allowed(): void
    {
        $box = Item::factory()->container()->create(['public_contents' => true]);
        $inner = Item::factory()->container()->at($box)->create(['name' => 'Kabelkiste']);
        Item::factory()->bulk(5, 'Rollen')->at($inner)->create(['name' => 'Gaffa']);
        Item::factory()->at($box)->create(['retired_at' => now()]);

        $this->get("/s/{$box->number}")->assertInertia(fn ($page) => $page
            ->where('kind', 'box')
            ->has('contents', 1)
            ->where('contents.0.name', 'Kabelkiste')
            ->where('contents.0.contents.0.name', 'Gaffa')
            ->where('contents.0.contents.0.quantity', 5));

        $box->update(['public_contents' => false]);
        $this->get("/s/{$box->number}")->assertInertia(fn ($page) => $page->where('kind', 'item')->where('contents', []));
    }

    public function test_locations_and_retired_items_show_little(): void
    {
        $location = Location::factory()->create(['code' => 'R03', 'full_code' => 'R03']);
        $item = Item::factory()->create(['retired_at' => now()]);

        $this->get('/s/R03')->assertInertia(fn ($page) => $page->where('kind', 'location')->where('location.code', 'R03'));
        $this->get("/s/{$item->number}")->assertInertia(fn ($page) => $page->where('kind', 'retired'));
    }

    public function test_unknown_codes_give_a_hint_and_a_create_button_to_members_who_may_create(): void
    {
        $this->get('/s/FSR5-004217')->assertInertia(fn ($page) => $page
            ->where('kind', 'unknown')
            ->where('message', 'Die Prüfziffer von FSR5-004217 stimmt nicht. Ist eine Ziffer vertippt?')
            ->where('createUrl', null));

        $this->actingAs($this->userWithRoles('FSR-Mitglied'))->get('/s/NICHTS')
            ->assertInertia(fn ($page) => $page->where('kind', 'unknown')->where('createUrl', '/inventory/items/create'));
    }

    public function test_reserved_numbers_open_the_create_form_with_the_number(): void
    {
        $reserved = app(ReserveNumbers::class)->handle($this->admin(), 1)->first();

        $this->get("/s/{$reserved->number}")->assertInertia(fn ($page) => $page->where('kind', 'reserved'));
        $this->actingAs($this->userWithRoles('FSR-Mitglied'))->get("/s/{$reserved->number}")
            ->assertRedirect('/inventory/items/create?number='.$reserved->number);
    }

    public function test_the_login_button_returns_to_the_full_page(): void
    {
        $item = Item::factory()->create();

        $this->get("/s/{$item->number}?login=1")->assertRedirect('/login');
        $this->assertSame(url("/s/{$item->number}?login=1"), session('url.intended'));
    }

    public function test_guests_are_rate_limited(): void
    {
        foreach (range(1, 30) as $attempt) {
            $this->get('/s/NICHTS')->assertOk();
        }

        $this->get('/s/NICHTS')->assertTooManyRequests();
    }

    public function test_scanned_short_links_count_like_the_code(): void
    {
        $item = Item::factory()->create();

        $this->assertSame('FSR5-004218', ShortLink::codeOf('https://other.example/s/FSR5-004218?x=1'));
        $this->assertSame('EU-014-R03.4', ShortLink::codeOf('https://stocks.example/s/EU-014-R03.4'));
        $this->assertSame('FSR5-004218', ShortLink::codeOf(' FSR5-004218 '));

        $this->actingAs($this->userWithRoles('Freies Mitglied'))
            ->getJson('/inventory/lookup?code='.urlencode("https://stocks.example/s/{$item->number}"))
            ->assertOk()->assertJsonPath('id', $item->id);
    }

    public function test_the_address_on_labels_is_a_setting(): void
    {
        config(['app.url' => 'http://localhost']);
        $links = app(ShortLink::class);

        $this->assertSame('http://localhost/s/FSR5-004218', $links->url('FSR5-004218'));

        $this->actingAs($this->admin())->put('/admin/settings', [
            'organization' => ['name' => 'FSR', 'short_name' => 'FSR', 'accent_color' => '#22948c'],
            'labels' => ['base_url' => 'https://stocks.fsr.example/'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('https://stocks.fsr.example/s/FSR5-004218', $links->url('FSR5-004218'));
        $this->assertFalse($links->isConfirmed());

        $this->actingAs($this->userWithRoles('FSR-Mitglied'))->post('/labels/confirm-address')->assertRedirect();
        $this->assertTrue($links->isConfirmed());
    }
}
