<?php

namespace Modules\Loans\Tests;

use Modules\Loans\Models\Contact;
use Modules\Loans\Models\Council;
use Modules\Loans\Models\Event;

/**
 * Contacts, councils and events.
 */
class ContactsTest extends LoansTestCase
{
    public function test_contacts_are_created_changed_and_found(): void
    {
        $user = $this->userWithPermissions('loans.view', 'loans.manage');

        $this->actingAs($user)->post('/loans/contacts', ['name' => 'Max Muster', 'email' => 'max@example.com', 'council_id' => Council::first()->id])
            ->assertRedirect();
        $contact = Contact::where('name', 'Max Muster')->firstOrFail();
        $this->assertSame(['Telefon', 'Straße', 'PLZ', 'Ort'], $contact->missingFields());

        $this->actingAs($user)->putJson("/loans/contacts/{$contact->id}", ['name' => 'Max Muster', 'email' => 'max@example.com', 'phone' => '1', 'street' => 'A 1', 'zip' => '1', 'city' => 'B'])
            ->assertOk()->assertJsonPath('missing', []);
        $this->actingAs($user)->getJson('/loans/contacts/search?search=max')->assertOk()->assertJsonPath('0.id', $contact->id);
        // created inside a form: the contact comes back as json
        $this->actingAs($user)->postJson('/loans/contacts', ['name' => 'Neu'])->assertCreated()->assertJsonPath('name', 'Neu');
    }

    public function test_contact_data_are_hidden_without_manage(): void
    {
        $loan = $this->draft([$this->item()]);

        $this->actingAs($this->userWithPermissions('loans.view'))->get("/loans/{$loan->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Loans::Loans/Show', false)->missing('loan.responsible.email')->where('loan.responsible.name', 'Erika Muster'));
        $this->actingAs($this->userWithPermissions('loans.view', 'loans.manage'))->get("/loans/{$loan->id}")
            ->assertInertia(fn ($page) => $page->where('loan.responsible.email', 'erika@example.com'));
        $this->actingAs($this->userWithPermissions('loans.view'))->get('/loans/contacts')->assertForbidden();
    }

    public function test_a_contact_with_loans_is_not_deleted(): void
    {
        $loan = $this->draft([$this->item()]);
        $user = $this->userWithPermissions('loans.view', 'loans.manage');

        $this->actingAs($user)->delete("/loans/contacts/{$loan->responsible_contact_id}")->assertSessionHasErrors('contact');
        $other = $this->contact(['name' => 'Ohne Buchung']);
        $this->actingAs($user)->delete("/loans/contacts/{$other->id}")->assertRedirect('/loans/contacts');
        $this->assertModelMissing($other);
    }

    public function test_councils_are_kept_by_admins(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/loans/councils', ['number' => 11, 'name' => 'FSR 11'])->assertSessionHasNoErrors();
        $council = Council::where('number', 11)->firstOrFail();
        $this->actingAs($admin)->put("/loans/councils/{$council->id}", ['number' => 11, 'name' => 'FSR 11', 'is_active' => false]);
        $this->assertNotContains($council->id, array_column(Council::options(), 'value'));

        $this->actingAs($this->userWithPermissions('loans.view', 'loans.manage'))->post('/loans/councils', ['number' => 12, 'name' => 'X'])->assertForbidden();
    }

    public function test_events_bracket_loans_and_suggest_their_time(): void
    {
        $user = $this->userWithPermissions('loans.view', 'loans.manage');
        $this->actingAs($user)->post('/loans/events', ['name' => 'Sommerfest', 'starts_at' => '2026-12-01 14:00', 'ends_at' => '2026-12-02 02:00', 'place' => 'Wiese'])->assertRedirect();
        $event = Event::firstOrFail();

        $this->actingAs($user)->get("/loans/create?event={$event->id}")
            ->assertInertia(fn ($page) => $page->where('loan.event_id', $event->id)->where('loan.pickup_at', '2026-12-01T14:00'));

        $loan = $this->draft([$this->item(['name' => 'Bierbank'])], data: ['event_id' => $event->id]);
        $this->actingAs($user)->get("/loans/events/{$event->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('loans.0.id', $loan->id)->where('materials.0.name', 'Bierbank'));
        $this->actingAs($user)->delete("/loans/events/{$event->id}")->assertSessionHasErrors('event');
        // free members see events, but do not change them
        $this->actingAs($this->userWithPermissions('loans.view'))->get('/loans/events')->assertOk();
        $this->actingAs($this->userWithPermissions('loans.view'))->post('/loans/events', ['name' => 'X'])->assertForbidden();
    }
}
