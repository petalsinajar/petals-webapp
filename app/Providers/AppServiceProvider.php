<?php

namespace App\Providers;

use App\Modules\Bookings\Events\BookingCreated;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Policies\BookingPolicy;
use App\Modules\Catalog\Availability\AvailabilityStore;
use App\Modules\Catalog\Availability\SqlAvailabilityStore;
use App\Modules\Catalog\Contracts\CatalogReader;
use App\Modules\Catalog\Services\CapacityWarnings;
use App\Modules\Catalog\Services\SqlCatalogReader;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Policies\EventPolicy;
use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Identity\Policies\VendorPolicy;
use App\Modules\Identity\Services\CbMessagingSmsSender;
use App\Modules\Identity\Services\LogSmsSender;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Payments\Gateways\PaystackGateway;
use App\Modules\Recommendations\Listeners\MarkRecommendationBooked;
use App\Modules\Social\Models\Comment;
use App\Modules\Social\Models\Post;
use App\Support\Push\OneSignalChannel;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Inventory lives in the main database, so stock and bookings share transactions.
        $this->app->singleton(AvailabilityStore::class, fn () => new SqlAvailabilityStore);
        $this->app->singleton(CatalogReader::class, fn () => new SqlCatalogReader);
        // Per request/job: stock warnings from ItemVariant's hook, read by whoever saved it.
        $this->app->scoped(CapacityWarnings::class);

        $this->app->singleton(PaymentGateway::class, fn () => match (config('eventapp.payments.gateway')) {
            'paystack' => new PaystackGateway,
            default => new FakeGateway,
        });

        $this->app->singleton(SmsSender::class, fn () => match (config('eventapp.sms.driver')) {
            'cbmessaging' => CbMessagingSmsSender::fromConfig(),
            default => new LogSmsSender,
        });
    }

    public function boot(): void
    {
        // Short, stable names in polymorphic columns (posts.author_type, follows, notifications...).
        Relation::enforceMorphMap([
            'user' => User::class,
            'vendor' => Vendor::class,
            'post' => Post::class,
            'comment' => Comment::class,
        ]);

        // Single objects are returned as-is; lists use { data, next_cursor }.
        JsonResource::withoutWrapping();

        // Fail loudly in development when a fill() silently drops a field.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Gate::policy(Vendor::class, VendorPolicy::class);
        Gate::policy(Event::class, EventPolicy::class);
        Gate::policy(Booking::class, BookingPolicy::class);

        // Cross-module reactions. Listeners are queued, so they never slow the request.
        Events::listen(BookingCreated::class, MarkRecommendationBooked::class);

        // `push` in a notification's via(): the phone, through OneSignal.
        Notification::extend('push', fn ($app) => $app->make(OneSignalChannel::class));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('posting', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('waitlist', fn (Request $request) => Limit::perMinute(config('eventapp.marketing.waitlist_per_minute'))->by('waitlist:'.$request->ip()));
        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinute(5)->by('otp-ip:'.$request->ip()),
            Limit::perHour(10)->by('otp-phone:'.preg_replace('/\D/', '', (string) $request->input('phone'))),
        ]);
    }
}
