<?php

namespace App\Modules\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** The public pre-launch site at petalsinajar.com. */
class LandingController extends Controller
{
    /** GET / */
    public function show(Request $request): View
    {
        $currency = config('eventapp.currency');

        // Shop-the-look pins. x/y are percentages of the photo, from the top left.
        $look = array_map(fn (array $pin) => $pin + ['price' => Money::format($pin['price_minor'], $currency)], [
            ['x' => 22, 'y' => 30, 'item' => 'White Chiavari chair', 'vendor' => 'Royal Canopies', 'price_minor' => 1500, 'unit' => 'per chair'],
            ['x' => 63, 'y' => 18, 'item' => 'Gold-trim canopy, 10×20m', 'vendor' => 'Royal Canopies', 'price_minor' => 250000, 'unit' => 'per day'],
            ['x' => 48, 'y' => 56, 'item' => 'Blush & gold centrepiece', 'vendor' => 'Glow Decor', 'price_minor' => 18000, 'unit' => 'per table'],
            ['x' => 82, 'y' => 44, 'item' => 'Warm fairy-light curtain', 'vendor' => 'Bass Sound & Lights', 'price_minor' => 45000, 'unit' => 'per day'],
        ]);

        return view('landing.index', [
            'look' => $look,
            'marketing' => config('eventapp.marketing'),
            'commissionPercent' => config('eventapp.bookings.commission_percent'),
            'depositPercent' => config('eventapp.bookings.default_deposit_percent'),
            'disputeHours' => config('eventapp.bookings.dispute_window_hours'),
            // Carried into both forms so sign-ups keep the campaign they came from.
            'utm' => array_filter($request->only(['utm_source', 'utm_medium', 'utm_campaign']), 'is_string'),
        ]);
    }

    /** GET /privacy */
    public function privacy(): View
    {
        return view('landing.privacy', ['marketing' => config('eventapp.marketing')]);
    }
}
