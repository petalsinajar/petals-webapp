<?php

namespace App\Modules\Social\Console;

use App\Modules\Identity\Models\User;
use App\Support\PhoneNumber;
use App\Support\Push\OneSignalChannel;
use App\Support\Push\PushMessage;
use Illuminate\Console\Command;

/**
 * `php artisan push:test 0244123456` — sends a test push to one user and says what
 * OneSignal did with it. The first thing to run when someone says "I got no alert".
 */
class SendTestPush extends Command
{
    protected $signature = 'push:test {user : User id, @handle or phone number}';

    protected $description = 'Send a test push to one user and show whether it reached a device';

    public function handle(OneSignalChannel $channel): int
    {
        $user = $this->findUser((string) $this->argument('user'));
        if (! $user) {
            $this->error('No such user.');

            return self::FAILURE;
        }
        $this->line("User: {$user->getFilamentName()} ({$user->id})");

        if (blank(config('eventapp.push.onesignal.rest_api_key'))) {
            $this->error('ONESIGNAL_REST_API_KEY is not set, so no push is ever sent. Set it in .env, then php artisan config:cache.');

            return self::FAILURE;
        }
        if (data_get($user->settings, 'notify.push', true) === false) {
            $this->warn('This user turned push off in the app (Profile settings), so real alerts skip them. Sending the test anyway.');
        }

        $response = $channel->post($user->id, new PushMessage('Test from Petalsinajar', 'If you can read this, push works.'));

        if ($response === null) {
            $this->error('Could not reach OneSignal (network or DNS from this server).');

            return self::FAILURE;
        }
        if (! $response->successful()) {
            $this->error("OneSignal refused it ({$response->status()}): ".json_encode($response->json('errors') ?? $response->body()));
            $this->line('A 401/403 usually means a wrong REST API key or app id.');

            return self::FAILURE;
        }
        if (blank($response->json('id')) || filled($response->json('errors'))) {
            $this->error('OneSignal accepted it but no phone is linked to this user: '.json_encode($response->json('errors')));
            $this->line('On the phone: sign in to the app, allow notifications, and check the user appears in OneSignal → Audience with this external id.');

            return self::FAILURE;
        }

        $this->info('Sent (OneSignal id '.$response->json('id').'). If it still does not show, check the phone\'s notification settings for the app.');

        return self::SUCCESS;
    }

    private function findUser(string $key): ?User
    {
        return User::find($key)
            ?? User::where('handle', ltrim($key, '@'))->first()
            ?? (($phone = PhoneNumber::normalize($key)) ? User::where('phone', $phone)->first() : null);
    }
}
