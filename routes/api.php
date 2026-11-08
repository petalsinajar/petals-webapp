<?php

use App\Modules\Bookings\Http\Controllers\BookingController;
use App\Modules\Bookings\Http\Controllers\CheckoutController;
use App\Modules\Catalog\Http\Controllers\CatalogController;
use App\Modules\Catalog\Http\Controllers\VendorInventoryController;
use App\Modules\Events\Http\Controllers\EventController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\ProfileController;
use App\Modules\Identity\Http\Controllers\VendorController;
use App\Modules\Identity\Http\Controllers\VendorMemberController;
use App\Modules\Media\Http\Controllers\UploadController;
use App\Modules\Payments\Http\Controllers\PaymentWebhookController;
use App\Modules\Recommendations\Http\Controllers\SuggestionController;
use App\Modules\Social\Http\Controllers\CommentController;
use App\Modules\Social\Http\Controllers\FeedController;
use App\Modules\Social\Http\Controllers\FollowController;
use App\Modules\Social\Http\Controllers\NotificationController;
use App\Modules\Social\Http\Controllers\PostController;
use App\Modules\Social\Http\Controllers\PostTagController;
use Illuminate\Support\Facades\Route;

/*
| All routes are served under /v1 (see bootstrap/app.php).
| JSON keys are snake_case. Lists return { data, next_cursor }.
*/

// ---- Public ----------------------------------------------------------------

Route::prefix('auth')->middleware('throttle:otp')->group(function () {
    Route::post('otp', [AuthController::class, 'requestOtp']);
    Route::post('verify', [AuthController::class, 'verify']);
});
Route::post('auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:api');

Route::post('webhooks/payments', PaymentWebhookController::class);

if (app()->environment(['local', 'testing'])) {
    Route::post('dev/payments/{payment}/succeed', [PaymentWebhookController::class, 'devSucceed']);
}

// Browsable signed out; personalised (my_reaction, blocks) with an access token.
Route::middleware(['auth.optional', 'throttle:api'])->group(function () {
    Route::get('feed/discover', [FeedController::class, 'discover']);
    Route::get('posts/{post}', [PostController::class, 'show']);
    Route::get('posts/{post}/comments', [CommentController::class, 'index']);
});

// ---- Signed in -------------------------------------------------------------

Route::middleware(['auth:sanctum', 'abilities:access', 'active', 'throttle:api'])->group(function () {

    // Identity
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [MeController::class, 'show']);
    Route::patch('me', [MeController::class, 'update']);
    Route::put('me/dashboard-credentials', [MeController::class, 'dashboardCredentials']);
    Route::post('me/devices', [MeController::class, 'registerDevice']);
    Route::get('me/events', [EventController::class, 'mine']);
    Route::get('me/bookings', [BookingController::class, 'mine']);
    Route::get('handles/{handle}/available', [MeController::class, 'handleAvailable']);
    Route::get('users/{handle}', [ProfileController::class, 'show']);
    Route::get('users/{user}/posts', [FeedController::class, 'userPosts'])->whereUlid('user');

    // Media
    Route::post('uploads', UploadController::class)->middleware('throttle:uploads');

    // Vendors
    Route::post('vendors', [VendorController::class, 'store']);
    Route::get('vendors/{vendor}', [VendorController::class, 'show']);
    Route::patch('vendors/{vendor}', [VendorController::class, 'update'])->whereUlid('vendor');
    Route::get('vendors/{vendor}/posts', [FeedController::class, 'vendorPosts'])->whereUlid('vendor');
    Route::get('vendors/{vendor}/packages', [CatalogController::class, 'vendorPackages']);

    Route::prefix('vendors/{vendor}')->whereUlid('vendor')->group(function () {
        Route::get('members', [VendorMemberController::class, 'index']);
        Route::post('members', [VendorMemberController::class, 'store']);
        Route::delete('members/{member}', [VendorMemberController::class, 'destroy']);

        Route::get('items', [VendorInventoryController::class, 'index']);
        Route::post('items', [VendorInventoryController::class, 'store']);
        Route::patch('items/{item}', [VendorInventoryController::class, 'update']);
        Route::delete('items/{item}', [VendorInventoryController::class, 'destroy']);
        Route::post('packages', [VendorInventoryController::class, 'storePackage']);
        Route::get('calendar', [VendorInventoryController::class, 'calendar']);
        Route::post('blocks', [VendorInventoryController::class, 'block']);
        Route::delete('blocks/{reservation}', [VendorInventoryController::class, 'unblock']);

        Route::get('bookings', [BookingController::class, 'forVendor']);
        Route::get('recommendation-stats', [SuggestionController::class, 'vendorStats']);
        Route::get('post-tags', [PostTagController::class, 'index']);
        Route::post('post-tags/{tag}', [PostTagController::class, 'update']);
    });

    // Catalog
    Route::get('categories', [CatalogController::class, 'categories']);
    Route::get('items', [CatalogController::class, 'index']);
    Route::get('items/{item}', [CatalogController::class, 'show']);
    Route::get('items/{item}/availability', [CatalogController::class, 'availability']);
    Route::get('items/{item}/posts', [FeedController::class, 'itemPosts']);
    Route::get('packages/{package}', [CatalogController::class, 'package']);

    // Events
    Route::post('events', [EventController::class, 'store']);
    Route::get('events/{event}', [EventController::class, 'show']);
    Route::patch('events/{event}', [EventController::class, 'update']);
    Route::get('events/{event}/members', [EventController::class, 'members']);
    Route::post('events/{event}/members', [EventController::class, 'invite']);
    Route::put('events/{event}/rsvp', [EventController::class, 'rsvp']);
    Route::get('events/{event}/posts', [FeedController::class, 'event']);
    Route::get('events/{event}/bookings', [BookingController::class, 'forEvent']);

    // Vendor suggestions for hosts
    Route::get('events/{event}/suggestions', [SuggestionController::class, 'index']);
    Route::post('suggestions/{recommendation}/open', [SuggestionController::class, 'open'])->whereUlid('recommendation');
    Route::post('suggestions/{recommendation}/dismiss', [SuggestionController::class, 'dismiss'])->whereUlid('recommendation');

    // Checkout and bookings
    Route::post('checkouts', [CheckoutController::class, 'store']);
    Route::get('checkouts/{checkout}', [CheckoutController::class, 'show']);
    Route::post('checkouts/{checkout}/pay', [CheckoutController::class, 'pay']);

    Route::get('bookings/{booking}', [BookingController::class, 'show']);
    Route::post('bookings/{booking}/accept', [BookingController::class, 'accept']);
    Route::post('bookings/{booking}/decline', [BookingController::class, 'decline']);
    Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel']);
    Route::post('bookings/{booking}/dispatch', [BookingController::class, 'markDispatched']);
    Route::post('bookings/{booking}/deliver', [BookingController::class, 'deliver']);
    Route::post('bookings/{booking}/return', [BookingController::class, 'markReturned']);

    // Social
    Route::get('feed/following', [FeedController::class, 'following']);
    Route::post('posts', [PostController::class, 'store'])->middleware('throttle:posting');
    Route::delete('posts/{post}', [PostController::class, 'destroy']);
    Route::put('posts/{post}/reaction', [PostController::class, 'react']);
    Route::delete('posts/{post}/reaction', [PostController::class, 'unreact']);
    Route::post('posts/{post}/shop-taps', [PostController::class, 'shopTap']);
    Route::post('posts/{post}/comments', [CommentController::class, 'store'])->middleware('throttle:posting');
    Route::delete('comments/{comment}', [CommentController::class, 'destroy']);

    Route::post('follows', [FollowController::class, 'store']);
    Route::delete('follows', [FollowController::class, 'destroy']);
    Route::get('{type}/{id}/followers', [FollowController::class, 'followers'])->whereIn('type', ['users', 'vendors'])->whereUlid('id');

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read', [NotificationController::class, 'markRead']);
});
