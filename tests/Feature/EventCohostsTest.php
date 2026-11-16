<?php

namespace Tests\Feature;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventMember;
use App\Modules\Events\Notifications\EventInvitation;
use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Hosts invite guests and co-hosts; co-hosts manage the event with them. */
class EventCohostsTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Event $event;

    /** @var list<array{0: string, 1: string}> texts sent: [phone, message] */
    private array $texts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->app->instance(SmsSender::class, new class($this->texts) implements SmsSender
        {
            /** @param list<array{0: string, 1: string}> $texts */
            public function __construct(private array &$texts) {}

            public function send(string $phone, string $message): void
            {
                $this->texts[] = [$phone, $message];
            }
        });

        $this->host = User::factory()->create(['display_name' => 'Ama']);
        $this->event = Event::factory()->create(['host_id' => $this->host->id, 'title' => 'Ama & Kojo']);
    }

    private function invite(array ...$invites)
    {
        return $this->postJson("/v1/events/{$this->event->id}/members", ['invites' => $invites]);
    }

    private function member(User $user): ?EventMember
    {
        return EventMember::where('event_id', $this->event->id)->where('user_id', $user->id)->first();
    }

    public function test_a_co_host_is_told_and_can_manage_the_event(): void
    {
        $kojo = User::factory()->create();
        $this->signIn($this->host);

        $this->invite(['user_id' => $kojo->id, 'role' => 'cohost'])->assertCreated()->assertJsonPath('invited', 1);

        $this->assertSame(['cohost', 'going'], [$this->member($kojo)->role, $this->member($kojo)->rsvp]);
        Notification::assertSentTo($kojo, EventInvitation::class, fn ($n) => str_contains($n->toPush($kojo)->body, 'added you as a co-host of Ama & Kojo')
            && $n->toPush($kojo)->route === "/events/{$this->event->id}");

        $this->signIn($kojo);
        $this->patchJson("/v1/events/{$this->event->id}", ['title' => 'Ama & Kojo’s wedding'])->assertOk();
        $this->invite(['user_id' => User::factory()->create()->id])->assertCreated();
    }

    public function test_inviting_a_guest_as_co_host_promotes_them_and_never_demotes(): void
    {
        $efua = User::factory()->create();
        $this->signIn($this->host);

        $this->invite(['user_id' => $efua->id])->assertJsonPath('invited', 1);
        $this->assertSame('guest', $this->member($efua)->role);
        $this->invite(['user_id' => $efua->id, 'role' => 'cohost'])->assertJsonPath('invited', 1);
        $this->assertSame('cohost', $this->member($efua)->role);
        $this->invite(['user_id' => $efua->id])->assertJsonPath('invited', 0);
        $this->assertSame('cohost', $this->member($efua)->role);
        // The creator is already the host.
        $this->invite(['user_id' => $this->host->id, 'role' => 'cohost'])->assertJsonPath('invited', 0);
    }

    public function test_someone_not_on_the_app_gets_a_text_and_the_invite_on_sign_up(): void
    {
        $this->signIn($this->host);
        $this->invite(['phone' => '024 555 1234', 'role' => 'cohost'])->assertJsonPath('invited', 1);

        $this->assertCount(1, $this->texts);
        [$to, $text] = $this->texts[0];
        $this->assertSame('+233245551234', $to);
        $this->assertStringContainsString('Ama invited you to co-host Ama & Kojo on Petalsinajar', $text);

        // They sign up with that number.
        $this->app['auth']->forgetGuards();
        $this->postJson('/v1/auth/otp', ['phone' => '0245551234'])->assertOk();
        $this->postJson('/v1/auth/verify', ['phone' => '0245551234', 'code' => '123456', 'device_name' => 'pixel'])->assertOk();

        $kofi = User::where('phone', '+233245551234')->sole();
        $this->assertSame('cohost', $this->member($kofi)?->role);
        $this->assertSame(0, EventMember::whereNotNull('invite_phone')->count());
    }

    public function test_only_the_host_changes_co_hosts_and_the_host_stays(): void
    {
        $kojo = User::factory()->create();
        $efua = User::factory()->create();
        $this->signIn($this->host);
        $this->invite(['user_id' => $kojo->id, 'role' => 'cohost'], ['user_id' => $efua->id, 'role' => 'cohost'])->assertCreated();
        $guest = User::factory()->create();
        $this->invite(['user_id' => $guest->id])->assertCreated();

        // A co-host can remove guests, not other co-hosts or the host.
        $this->signIn($kojo);
        $this->deleteJson("/v1/events/{$this->event->id}/members/{$this->member($efua)->id}")->assertForbidden();
        $this->patchJson("/v1/events/{$this->event->id}/members/{$this->member($efua)->id}", ['role' => 'guest'])->assertForbidden();
        $this->deleteJson("/v1/events/{$this->event->id}/members/{$this->member($this->host)->id}")->assertUnprocessable();
        $this->deleteJson("/v1/events/{$this->event->id}/members/{$this->member($guest)->id}")->assertOk();

        // The host demotes and removes co-hosts.
        $this->signIn($this->host);
        $this->patchJson("/v1/events/{$this->event->id}/members/{$this->member($efua)->id}", ['role' => 'guest'])
            ->assertOk()->assertJsonPath('role', 'guest');
        $this->deleteJson("/v1/events/{$this->event->id}/members/{$this->member($kojo)->id}")->assertOk();
        $this->assertNull($this->member($kojo));
        $this->patchJson("/v1/events/{$this->event->id}/members/{$this->member($this->host)->id}", ['role' => 'guest'])
            ->assertUnprocessable();
    }

    public function test_guests_cant_invite(): void
    {
        $guest = User::factory()->create();
        EventMember::create(['event_id' => $this->event->id, 'user_id' => $guest->id, 'role' => 'guest', 'rsvp' => 'going']);
        $this->signIn($guest);

        $this->invite(['user_id' => User::factory()->create()->id])->assertForbidden();
    }

    public function test_people_search_finds_by_name_or_handle_without_phone_numbers(): void
    {
        User::factory()->create(['handle' => 'kojo.addo', 'display_name' => 'Kojo Addo']);
        User::factory()->create(['handle' => 'efua', 'display_name' => 'Efua Kojo-Mensah']);
        User::factory()->create(['handle' => 'yaw', 'display_name' => 'Yaw']);
        $this->signIn($this->host);

        $rows = $this->getJson('/v1/users?q=@kojo')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['kojo.addo', 'efua'], array_column($rows, 'handle'));
        $this->assertArrayNotHasKey('phone', $rows[0]);
        $this->getJson('/v1/users?q=ama')->assertOk()->assertJsonCount(0, 'data'); // not yourself
    }
}
