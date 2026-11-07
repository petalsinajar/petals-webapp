<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\WaitlistSignups\Pages\ManageWaitlistSignups;
use App\Modules\Identity\Models\User;
use App\Modules\Marketing\Enums\WaitlistRole;
use App\Modules\Marketing\Models\WaitlistSignup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The public landing page and its pre-launch waitlist. */
class WaitlistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function host(array $overrides = []): array
    {
        return $overrides + [
            'role' => 'host',
            'name' => 'Esi Mensah',
            'whatsapp_phone' => '024 123 4567',
            'city' => 'Accra',
            'event_types' => ['wedding'],
        ];
    }

    private function vendor(array $overrides = []): array
    {
        return $overrides + [
            'role' => 'vendor',
            'name' => 'Kojo Asante',
            'business_name' => 'Royal Canopies',
            'category' => 'tents',
            'whatsapp_phone' => '0201234567',
            'city' => 'Kumasi',
        ];
    }

    public function test_landing_page_renders_with_share_tags_and_both_forms(): void
    {
        $this->get('/?utm_source=whatsapp&utm_campaign=launch')
            ->assertOk()
            ->assertSee('Every celebration, collected.')
            ->assertSee('property="og:image" content="'.asset('images/landing/og-share.jpg').'"', false)
            ->assertSee('name="twitter:card" content="summary_large_image"', false)
            ->assertSee('name="role" value="host"', false)
            ->assertSee('name="role" value="vendor"', false)
            ->assertSee('name="utm_source" value="whatsapp"', false)
            ->assertSee('GH₵2,500');

        $this->get('/privacy')->assertOk();
    }

    public function test_a_host_joins_the_waitlist(): void
    {
        $this->postJson('/waitlist', $this->host(['utm_source' => 'instagram', 'utm_medium' => 'bio']))
            ->assertCreated()
            ->assertJson(['role' => 'host', 'already_joined' => false]);

        $signup = WaitlistSignup::sole();
        $this->assertSame('+233241234567', $signup->whatsapp_phone);
        $this->assertSame(WaitlistRole::Host, $signup->role);
        $this->assertSame(['wedding'], $signup->event_types);
        $this->assertSame(['utm_source' => 'instagram', 'utm_medium' => 'bio'], $signup->source);
        $this->assertNull($signup->business_name);
    }

    public function test_a_vendor_joins_with_their_business(): void
    {
        $this->postJson('/waitlist', $this->vendor())->assertCreated()->assertJson(['role' => 'vendor']);

        $this->assertDatabaseHas('waitlist_signups', [
            'role' => 'vendor',
            'business_name' => 'Royal Canopies',
            'category' => 'tents',
            'whatsapp_phone' => '+233201234567',
            'event_types' => null,
            'source' => null,
        ]);
    }

    public function test_vendors_must_give_a_business_name_and_category(): void
    {
        $this->postJson('/waitlist', $this->vendor(['business_name' => '', 'category' => 'spaceships']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['business_name', 'category']);

        $this->assertDatabaseCount('waitlist_signups', 0);
    }

    public function test_hosts_cannot_set_vendor_fields(): void
    {
        $this->postJson('/waitlist', $this->host(['business_name' => 'Not a vendor', 'category' => 'tents']))->assertCreated();

        $this->assertDatabaseHas('waitlist_signups', ['role' => 'host', 'business_name' => null, 'category' => null]);
    }

    public function test_input_is_validated(): void
    {
        $this->postJson('/waitlist', $this->host(['whatsapp_phone' => '12', 'name' => '', 'city' => str_repeat('a', 81)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['whatsapp_phone', 'name', 'city']);

        $this->postJson('/waitlist', $this->host(['role' => 'admin']))->assertJsonValidationErrors('role');
        $this->postJson('/waitlist', $this->host(['event_types' => ['funeral']]))->assertJsonValidationErrors('event_types.0');
    }

    public function test_joining_twice_keeps_one_sign_up_per_role(): void
    {
        $this->postJson('/waitlist', $this->host())->assertCreated();
        $this->postJson('/waitlist', $this->host(['whatsapp_phone' => '+233 24 123 4567', 'name' => 'Esi M.']))
            ->assertOk()
            ->assertJson(['already_joined' => true]);

        $this->assertSame('Esi Mensah', WaitlistSignup::sole()->name);

        // The same number can also sign up as a vendor.
        $this->postJson('/waitlist', $this->vendor(['whatsapp_phone' => '0241234567']))->assertCreated();
        $this->assertDatabaseCount('waitlist_signups', 2);
    }

    public function test_the_honeypot_quietly_drops_bots(): void
    {
        $this->postJson('/waitlist', $this->host(['website' => 'https://spam.example']))
            ->assertCreated()
            ->assertJson(['role' => 'host']);

        $this->assertDatabaseCount('waitlist_signups', 0);
    }

    public function test_sign_ups_are_throttled_per_ip(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postJson('/waitlist', $this->host(['whatsapp_phone' => "02412345{$i}0"]))->assertCreated();
        }

        $this->postJson('/waitlist', $this->host(['whatsapp_phone' => '0241234599']))->assertTooManyRequests();
        $this->assertDatabaseCount('waitlist_signups', 5);
    }

    public function test_the_form_works_without_javascript(): void
    {
        $this->post('/waitlist', $this->vendor())
            ->assertRedirect(url('/').'#vendors')
            ->assertSessionHas('waitlist_joined.role', 'vendor');

        $this->from('/')->post('/waitlist', $this->host(['name' => '']))
            ->assertRedirect('/')
            ->assertSessionHasErrors('name', null, 'host');
    }

    public function test_admins_can_list_and_export_sign_ups(): void
    {
        WaitlistSignup::create(['role' => 'host', 'name' => '=HYPERLINK("x")', 'whatsapp_phone' => '+233241234567', 'city' => 'Accra', 'event_types' => ['wedding', 'birthday']]);
        WaitlistSignup::create(['role' => 'vendor', 'name' => 'Kojo', 'business_name' => 'Royal Canopies', 'category' => 'tents', 'whatsapp_phone' => '+233201234567', 'city' => 'Kumasi']);

        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin/waitlist-signups')->assertOk()->assertSee('Royal Canopies');

        $csv = Livewire::test(ManageWaitlistSignups::class)->instance()->exportCsv();
        ob_start();
        $csv->sendContent();
        $rows = array_map(str_getcsv(...), array_filter(explode("\n", ob_get_clean())));

        $this->assertSame('whatsapp_phone', $rows[0][3]);
        // A name that looks like a formula is neutralised; phone numbers keep their +.
        $this->assertSame(['host', "'=HYPERLINK(\"x\")", '+233241234567', 'Accra', '', '', 'wedding|birthday'], array_slice($rows[1], 1, 7));
        $this->assertSame(['vendor', 'Kojo', '+233201234567', 'Kumasi', 'Royal Canopies', 'tents', ''], array_slice($rows[2], 1, 7));
    }
}
