<?php

namespace Tests\Unit;

use App\Modules\Campaigns\Services\DeepLinks;
use App\Modules\Campaigns\Services\SendWindow;
use App\Modules\Campaigns\Services\SmsCounter;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** SMS segments and cost, quiet hours and deep links. */
class CampaignRulesTest extends TestCase
{
    public function test_gsm7_messages_fit_160_then_split_at_153(): void
    {
        $this->assertSame(['encoding' => 'gsm7', 'units' => 160, 'segments' => 1, 'per_segment' => 160], SmsCounter::count(str_repeat('a', 160)));
        $this->assertSame(2, SmsCounter::count(str_repeat('a', 161))['segments']);
        $this->assertSame(2, SmsCounter::count(str_repeat('a', 306))['segments']);
        $this->assertSame(3, SmsCounter::count(str_repeat('a', 307))['segments']);
        $this->assertSame(0, SmsCounter::count('')['segments']);
    }

    public function test_extension_characters_take_two_places(): void
    {
        $count = SmsCounter::count(str_repeat('a', 158).'€');

        $this->assertSame('gsm7', $count['encoding']);
        $this->assertSame(160, $count['units']);
        $this->assertSame(1, $count['segments']);
        $this->assertSame(2, SmsCounter::count(str_repeat('a', 159).'€')['segments']);
    }

    public function test_anything_outside_gsm7_switches_to_ucs2(): void
    {
        $this->assertSame(['encoding' => 'ucs2', 'units' => 70, 'segments' => 1, 'per_segment' => 70], SmsCounter::count(str_repeat('a', 69).'’'));
        $this->assertSame(2, SmsCounter::count(str_repeat('a', 70).'’')['segments']);
        // An emoji is two UTF-16 units.
        $this->assertSame(2, SmsCounter::count('🎉')['units']);
        $this->assertSame(3, SmsCounter::count(str_repeat('ж', 135))['segments']); // 135 / 67
    }

    public function test_cost_is_segments_times_recipients_times_the_configured_price(): void
    {
        config(['eventapp.campaigns.sms.cost_per_segment_minor' => 4]);

        $this->assertSame(2 * 1000 * 4, SmsCounter::costMinor(2, 1000));
    }

    public function test_quiet_hours_wrap_midnight_in_the_audience_timezone(): void
    {
        config(['eventapp.campaigns.audience_timezone' => 'Africa/Lagos']); // UTC+1, to see the conversion

        $at = fn (string $local) => CarbonImmutable::parse($local, 'Africa/Lagos')->utc();
        $this->assertFalse(SendWindow::isQuiet($at('2026-11-23 20:59')));
        $this->assertTrue(SendWindow::isQuiet($at('2026-11-23 21:00')));
        $this->assertTrue(SendWindow::isQuiet($at('2026-11-24 03:00')));
        $this->assertFalse(SendWindow::isQuiet($at('2026-11-24 08:00')));

        $this->assertEquals($at('2026-11-24 08:00'), SendWindow::nextAllowed($at('2026-11-23 22:30')));
        $this->assertEquals($at('2026-11-24 08:00'), SendWindow::nextAllowed($at('2026-11-24 06:15')));
        $this->assertEquals($at('2026-11-24 12:00'), SendWindow::nextAllowed($at('2026-11-24 12:00')));
    }

    public function test_deep_links_become_app_paths(): void
    {
        $this->assertSame('/events/01abc', DeepLinks::path(['type' => 'event', 'id' => '01abc']));
        $this->assertSame('/discover', DeepLinks::path(['type' => 'discover']));
        $this->assertSame('/events/new', DeepLinks::path(['type' => 'create_event']));
        // An automation's event link opens the recipient's own event.
        $this->assertSame('/events/e9', DeepLinks::path(['type' => 'event'], ['event_id' => 'e9']));
        $this->assertNull(DeepLinks::path(['type' => 'vendor']));
        $this->assertNull(DeepLinks::path(null));
    }
}
