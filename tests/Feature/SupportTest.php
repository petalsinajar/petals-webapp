<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\SupportRequests\Pages\ManageSupportRequests;
use App\Modules\Identity\Models\User;
use App\Modules\Marketing\Enums\SupportTopic;
use App\Modules\Marketing\Models\SupportRequest;
use App\Modules\Marketing\Notifications\SupportRequestReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** The public support page and its contact form. */
class SupportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
    }

    public function test_page_explains_account_deletion_and_preselects_the_topic(): void
    {
        $this->get('/support')
            ->assertOk()
            ->assertSee('id="delete-account"', false)
            ->assertSee('What we keep, and why');

        $this->get('/support?topic=account_deletion')
            ->assertSee('<option value="account_deletion" selected', false);

        $this->get('/')->assertSee(route('support'), false);
        $this->get('/privacy')->assertSee(route('support').'#delete-account', false);
    }

    public function test_message_is_stored_and_emailed_to_the_team(): void
    {
        $this->post('/support', [
            'topic' => 'booking',
            'name' => ' Esi Mensah ',
            'email' => 'Esi@Example.com',
            'message' => 'My canopy arrived late.',
        ])->assertRedirect(route('support').'#contact')->assertSessionHas('support_sent');

        $request = SupportRequest::sole();
        $this->assertSame(SupportTopic::Booking, $request->topic);
        $this->assertSame('Esi Mensah', $request->name);
        $this->assertSame('esi@example.com', $request->email);
        $this->assertNull($request->user_id);

        Notification::assertSentTo(new AnonymousNotifiable, SupportRequestReceived::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === config('eventapp.marketing.support_email')
                && $n->request->is($request));
    }

    public function test_deletion_request_links_the_matching_account_without_saying_so(): void
    {
        $user = User::factory()->create(['phone' => '+233241234567']);

        $withAccount = $this->post('/support', [
            'topic' => 'account_deletion', 'name' => 'Kojo', 'phone' => '024 123 4567', 'message' => 'Please delete my account.',
        ]);
        $withoutAccount = $this->post('/support', [
            'topic' => 'account_deletion', 'name' => 'Ama', 'phone' => '020 000 0000', 'message' => 'Please delete my account.',
        ]);

        $this->assertSame($user->id, SupportRequest::where('name', 'Kojo')->sole()->user_id);
        $this->assertNull(SupportRequest::where('name', 'Ama')->sole()->user_id);
        $this->assertSame(
            $withAccount->getSession()->get('support_sent'),
            $withoutAccount->getSession()->get('support_sent'),
        );
        $this->assertStringContainsString('confirm', $withAccount->getSession()->get('support_sent'));
    }

    public function test_needs_a_way_to_reply_and_a_valid_topic(): void
    {
        $this->post('/support', ['topic' => 'nonsense', 'name' => 'Esi', 'message' => 'Hi'])
            ->assertSessionHasErrors(['topic', 'email', 'phone']);

        $this->post('/support', ['topic' => 'general', 'name' => 'Esi', 'phone' => 'call me', 'message' => 'Hi'])
            ->assertSessionHasErrors(['phone']);

        $this->assertSame(0, SupportRequest::count());
    }

    public function test_honeypot_looks_successful_but_stores_nothing(): void
    {
        $this->post('/support', ['website' => 'spam.example', 'topic' => 'general', 'name' => 'Bot', 'email' => 'bot@example.com', 'message' => 'Buy now'])
            ->assertRedirect(route('support').'#contact')
            ->assertSessionHas('support_sent');

        $this->assertSame(0, SupportRequest::count());
        Notification::assertNothingSent();
    }

    public function test_admins_resolve_requests(): void
    {
        $admin = User::factory()->admin()->create();
        $request = SupportRequest::create(['topic' => 'general', 'name' => 'Esi', 'email' => 'esi@example.com', 'message' => 'Hello']);
        $this->actingAs($admin);

        Livewire::test(ManageSupportRequests::class)
            ->assertCanSeeTableRecords([$request])
            ->callTableAction('resolve', $request, ['note' => 'Answered by email']);

        $request->refresh();
        $this->assertNotNull($request->resolved_at);
        $this->assertSame($admin->id, $request->resolved_by);
        $this->assertSame('Answered by email', $request->resolution_note);
    }
}
