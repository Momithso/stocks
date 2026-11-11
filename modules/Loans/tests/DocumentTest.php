<?php

namespace Modules\Loans\Tests;

use Modules\Loans\Enums\DocumentType;
use Modules\Loans\Support\ContractTemplate;
use Modules\Loans\Support\Snapshot;

/**
 * Documents: pdfs, the hash of the signed content, the contract text.
 */
class DocumentTest extends LoansTestCase
{
    public function test_contract_and_list_are_frozen_with_their_hash(): void
    {
        $loan = $this->issued([$this->item(['name' => 'Beamer'])]);
        $contract = $loan->documents()->where('type', DocumentType::Contract)->firstOrFail();
        $list = $loan->documents()->where('type', DocumentType::MaterialList)->firstOrFail();

        $this->assertSame($contract->hash, $list->hash);
        $this->assertSame(Snapshot::hash($contract->snapshot), $contract->hash);
        $this->assertTrue($contract->isUnchanged());
        $this->assertStringContainsString('Erika Muster', $contract->snapshot['contract']);
        $this->assertStringContainsString($loan->number, $loan->contract_text);
        $this->assertSame($contract->hash, $loan->signatures()->first()->document_hash);

        $this->actingAs($this->admin())->get("/loans/{$loan->id}/documents/{$contract->id}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->admin())->get("/loans/{$loan->id}/documents/{$list->id}")->assertOk();
        $this->actingAs($this->admin())->get("/loans/{$loan->id}/confirmation.pdf")->assertOk();
    }

    public function test_a_changed_content_no_longer_matches_its_hash(): void
    {
        $loan = $this->issued([$this->item()]);
        $contract = $loan->documents()->where('type', DocumentType::Contract)->firstOrFail();

        $snapshot = $contract->snapshot;
        $snapshot['return'] = '31.12.2099, 12:00';
        $contract->forceFill(['snapshot' => $snapshot])->save();

        $this->assertFalse($contract->refresh()->isUnchanged());
        $this->actingAs($this->admin())->get("/loans/{$loan->id}")->assertInertia(fn ($page) => $page->where('documents.0.unchanged', false));
    }

    public function test_previews_before_the_issue_have_no_signatures(): void
    {
        $loan = $this->confirmed([$this->item()]);

        $this->actingAs($this->admin())->get("/loans/{$loan->id}/preview/contract.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->admin())->get("/loans/{$loan->id}/preview/list.pdf")->assertOk();
    }

    public function test_signatures_on_paper_are_marked_without_template_code(): void
    {
        $html = view('loans::pdf.signatures', [
            'roles' => ['borrower' => 'Verantwortliche Person'],
            'signatures' => [['role' => 'Verantwortliche Person', 'name' => 'Erika Muster', 'image' => null, 'signed_at' => '2026-11-11 10:00:00', 'on_paper' => true]],
        ])->render();

        $this->assertStringContainsString('10:00 Uhr · auf Papier', $html);
        $this->assertStringNotContainsString('@', $html);
    }

    public function test_the_contract_text_is_escaped_and_formatted(): void
    {
        $html = ContractTemplate::render("## Titel {number}\n\n1. Eins <b>\n2. **Zwei**\n\n- Punkt\n\nAbsatz {unknown}", ['number' => 'B-1']);

        $this->assertSame("<h2>Titel <span class=\"nowrap\">B-1</span></h2>\n<ol>\n<li>Eins &lt;b&gt;</li>\n<li><strong>Zwei</strong></li>\n</ol>\n<ul>\n<li>Punkt</li>\n</ul>\n<p>Absatz {unknown}</p>", $html);
    }

    public function test_the_contract_text_is_kept_in_the_settings(): void
    {
        $admin = $this->admin();
        $data = ['loans' => ['lender' => 'AStA', 'place' => null, 'buffer_hours' => 2, 'number_prefix' => 'L', 'contract_template' => '## Eigener Vertrag {number}']];

        $this->actingAs($admin)->put('/loans/settings', $data)->assertSessionHasNoErrors();
        $this->assertSame('L-'.now()->year.'-0001', $this->draft()->number);
        $this->actingAs($admin)->get('/loans/settings/preview.pdf')->assertOk();

        $loan = $this->issued([$this->item()]);
        $this->assertStringContainsString('Eigener Vertrag', $loan->contract_text);

        // back to the text that comes with the module; the issued loan keeps its text
        $this->actingAs($admin)->put('/loans/settings', [...$data, 'reset_text' => true])->assertSessionHasNoErrors();
        $this->actingAs($admin)->get('/loans/settings')->assertInertia(fn ($page) => $page->where('isDefaultText', true));
        $this->assertStringContainsString('Eigener Vertrag', $loan->refresh()->contract_text);
        $this->actingAs($this->userWithPermissions('loans.view', 'loans.manage'))->get('/loans/settings')->assertForbidden();
    }
}
