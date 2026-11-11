<?php

namespace App\Modules\Bookings\Console;

use App\Modules\Bookings\Services\VendorTrustSignals;
use App\Modules\Identity\Models\Vendor;
use Illuminate\Console\Command;

/** Nightly: response time, acceptance and cancellation rates cached on each vendor. */
class ComputeVendorTrust extends Command
{
    protected $signature = 'vendors:trust-signals';

    protected $description = 'Recompute vendor response time, acceptance rate and cancellation rate';

    public function handle(VendorTrustSignals $signals): int
    {
        $count = 0;
        Vendor::query()->chunkById(200, function ($vendors) use ($signals, &$count) {
            foreach ($vendors as $vendor) {
                $signals->refresh($vendor);
                $count++;
            }
        });

        $this->info("Updated {$count} vendors.");

        return self::SUCCESS;
    }
}
