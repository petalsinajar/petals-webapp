<?php

use App\Modules\Identity\Models\OtpCode;
use Illuminate\Support\Facades\Schedule;

/*
| Run the scheduler on the server with one cron entry (Forge sets this up):
|   * * * * * php /path/to/artisan schedule:run
*/

// Release expired stock holds and expire their bookings.
Schedule::command('inventory:release-expired-holds')->everyMinute()->withoutOverlapping()->onOneServer();

// Returned bookings past the dispute window become completed (books vendor earnings).
Schedule::command('bookings:complete-returned')->hourly()->withoutOverlapping()->onOneServer();

// Discover ranking.
Schedule::command('posts:rescore')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Vendor suggestions for upcoming events: queues jobs on the "recommendations" queue.
Schedule::command('recommendations:run')
    ->dailyAt(config('eventapp.recommendations.run_at'))
    ->timezone(config('eventapp.business_timezone'))
    ->withoutOverlapping()->onOneServer();

// Housekeeping.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('model:prune', ['--model' => [OtpCode::class]])->daily();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
