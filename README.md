# Petalsinajar API

Backend for Petalsinajar: a social app for weddings, birthdays and corporate events, with built-in rentals. Hosts create events and post photos; anyone can rent the chairs, tents, decor and sound they see in posts; vendors run their business from the same app or a web dashboard.

- **Laravel 13** REST API under `/v1` for the Flutter app (Sanctum bearer tokens)
- **Filament 5** dashboards: `/admin` (platform team) and `/vendor/{handle}` (vendors, multi-tenant)
- **PostgreSQL + PostGIS**, one database for everything: accounts, events, inventory and the stock ledger, bookings, money and social data

## How it's organised

One Laravel app split into modules. Each module owns its models, HTTP layer and services; modules talk through small interfaces, not each other's internals.

```
app/
  Modules/
    Identity/   users, vendors, vendor_members, @handles, phone OTP, tokens
    Catalog/    categories, items, variants, packages + Availability (the stock ledger)
    Events/     events, guests, RSVPs
    Bookings/   checkout, bookings, lifecycle, hold sweeper, completion
    Payments/   payment gateway (fake | Paystack), payments, append-only ledger, refunds
    Social/     posts with shop tags, follows, reactions, comments, feeds, notifications
    Recommendations/  vendor suggestions for hosts' upcoming events (daily, queued)
    Media/      direct uploads to R2 / Cloudflare Stream, image renditions, moderation hook, cleanup
    Reviews/    verified two-way reviews of completed bookings: double-blind, replies, reports
    Subscriptions/  vendor plans: limits, features, trial, billing (Paystack), featured weeks, analytics
  Filament/
    Admin/      users, vendor verification, bookings & disputes, post and review moderation
    Vendor/     inventory with variants, booking schedule with accept/decline/dispatch/return
  Support/      Money (minor units), Actor (user|vendor), PhoneNumber, Paginated, Geo (service areas)
```

Rules that hold everywhere:

1. **Money is integer minor units** (`*_minor`, pesewas for GHS). Never floats.
2. **Stock is quantity over time.** Each variant has a quantity; reservations take part of it for a date window. Reserving is a guarded `UPDATE` on a per-day counter (`reserved + qty <= capacity`), so the last 50 chairs can't be sold twice.
3. **Bookings snapshot what was sold**: titles, unit prices and the vendor's cancellation policy are copied at booking time.
4. **Vendors are social actors.** Posts, follows and comments have an author that is a user or a vendor (`{type, id}`).
5. **One account, many roles.** A user can belong to several vendors as `owner`, `manager` or `staff`, checked on every request.
6. **All ids are ULIDs**, including items and variants; foreign keys are real.

## Stock and bookings

Stock and bookings share one database, so a checkout is **one transaction**: `CheckoutService` reserves every line as a **hold** (all-or-nothing across vendors), then writes the checkout, bookings and lines. Out of stock or any failure rolls all of it back.

`SqlAvailabilityStore` keeps one row per variant per day in `availability_days`. Reserving inserts the day's counter if it's missing, then runs `UPDATE … SET reserved = reserved + ? WHERE reserved + ? <= capacity`; zero rows updated means that day is sold out (409). Counters are always touched in `(variant_id, day)` order, so concurrent checkouts can't deadlock. Releasing and confirming are conditional on the reservation's status, so repeating them is harmless. Booking status changes release stock inside the same transaction (`BookingService::transition`).

Holds last until the vendor responds (24 h), then until the deposit is paid (24 h). Instant-book items skip vendor approval with a 15-minute hold. A successful payment webhook turns the hold into a booking reservation; returning the items releases it.

```
pending_vendor → accepted → confirmed → out_for_delivery → delivered → returned → completed
       ↘ declined / expired      ↘ cancelled (refund per policy snapshot)   ↘ disputed → completed
```

## API conventions

- Base path `/v1`, JSON keys in `snake_case`.
- Auth: `POST /v1/auth/otp` → `POST /v1/auth/verify` returns `access_token` (60 min) and `refresh_token` (60 days). Send `Authorization: Bearer <access_token>`. On 401, call `POST /v1/auth/refresh` with `{ refresh_token }` once; it rotates both tokens.
- Lists: `{ "data": [...], "next_cursor": "..." | null }`; pass `?cursor=` for the next page.
- Errors: `{ "message": "...", "errors": { "field": ["..."] } }`. Out of stock returns **409** with a `stock` object (`variant_id`, `day`, `requested`, `available`).
- Booking actions are verbs: `POST /v1/bookings/{id}/accept|decline|cancel|dispatch|deliver|return`.
- `php artisan route:list --path=v1` lists every endpoint.
- **Images and video** are uploaded first, then referenced by id (see "Uploads" below). Posts, items and packages take `media: [id, …]`; profiles and events take `avatar_media_id`, `logo_media_id`, `cover_media_id`. Responses return media objects:
  `{ id, kind, status, width, height, blurhash, urls: { thumb, feed, full } | { hls, poster }, duration_ms }` (`urls` is null until `status` is `ready`). `avatar_url` / `logo_url` / `cover_url` are still returned for convenience.

| Area | Main routes |
| --- | --- |
| Auth & me | `auth/otp`, `auth/verify`, `auth/refresh`, `auth/logout`, `GET/PATCH me`, `me/events`, `me/bookings`, `me/devices`, `me/dashboard-credentials` |
| Vendors | `vendors?lat=&lng=&category=&event_type=` (serving that venue, nearest first), `POST vendors`, `GET/PATCH vendors/{id}`, `vendors/{id}/members`, `vendors/{id}/items`, `vendors/{id}/calendar`, `vendors/{id}/blocks`, `vendors/{id}/bookings`, `vendors/{id}/post-tags` |
| Catalog | `categories`, `items?category=&event_type=&q=&lat=&lng=` (lat/lng: vendors whose own radius covers the point), `items/{id}`, `items/{id}/availability`, `items/{id}/posts`, `packages/{id}` |
| Events | `POST events`, `events/{id}`, `events/{id}/members`, `PUT events/{id}/rsvp`, `events/{id}/posts`, `events/{id}/bookings`, `events/{id}/suggestions` |
| Suggestions | `POST suggestions/{id}/open`, `POST suggestions/{id}/dismiss`, `vendors/{id}/recommendation-stats?days=` |
| Checkout | `POST checkouts`, `GET checkouts/{id}`, `POST checkouts/{id}/pay`, `POST webhooks/payments` |
| Uploads | `POST uploads`, `POST uploads/{id}/complete`, `GET media/{id}`, `POST webhooks/video` |
| Plans | `plans`, `vendors/{id}/subscription` (+ `/trial`, `/checkout`, `/cancel`, `/resume`, `/auto-renew`), `vendors/{id}/featured-credits/{credit}/use`, `vendors/{id}/analytics?days=` |
| Reviews | `POST/GET bookings/{id}/reviews`, `PATCH reviews/{id}`, `POST reviews/{id}/reply`, `POST reviews/{id}/report`, `vendors/{id}/reviews?sort=recent\|highest\|lowest&rating=&with_photos=`, `items/{id}/reviews`, `customers/{id}/vendor-reviews` |
| Social | `feed/following`, `feed/discover?event_type=`, `posts`, `posts/{id}/reaction`, `posts/{id}/comments`, `posts/{id}/shop-taps`, `follows`, `notifications` |

## Uploads

Files never pass through this server. The app:

1. `POST /v1/uploads { kind: image|video, mime, bytes, purpose: post|item|avatar|cover|event_cover|review, vendor_id?, duration_ms? }`. Allowed: JPEG, PNG, HEIC, WebP up to 15 MB; MP4 and MOV up to 200 MB and 3 minutes (`config/eventapp.php` → `media`). Rate limited per user.
2. Uploads the file as the response says, within 10 minutes:
   - `upload.method = PUT`: one request to `upload.url` with exactly `upload.headers` (type and size are signed, so a different file is refused by storage).
   - `upload.method = multipart` (images over 8 MB): PUT each of `upload.parts[].url`, keep each response's `ETag`.
   - `upload.method = POST` (video): `multipart/form-data` to `upload.url` with the file in `upload.field`, straight to Cloudflare Stream.
3. `POST /v1/uploads/{media_id}/complete` (with `{ parts: [{ part_number, etag }] }` for multipart). The server checks the file is there with the declared size and real type (magic bytes); a mismatch fails the upload and deletes the file.
4. Uses the `media_id` right away in a post (it publishes once processing is done), or polls `GET /v1/media/{id}` until `status` is `ready` for anything else.

Processing runs on the `media` queue (`ProcessMedia`, idempotent): images are auto-rotated, stripped of all metadata (EXIF, GPS), saved as WebP at 320 / 1080 / 2048 px with a blurhash, and the original is deleted. Videos are transcoded to HLS by Stream, which calls `POST /v1/webhooks/video` when done (signature checked). Every media passes `ContentModeration` before it's ready (a no-op `AllowAllModeration` until a real service is bound in `AppServiceProvider`). Rows store keys only; URLs are built from `MEDIA_CDN_URL` / `CLOUDFLARE_STREAM_DELIVERY_URL` when responding. Uploads never attached to anything are deleted after 24 hours (`media:prune`, daily), and a post's or item's media is deleted with it.

## Reviews

Only a **completed** booking can be reviewed, for 30 days after completion, once per side: the customer reviews the vendor (`customer_to_vendor`, public) and any member of the vendor's team reviews the customer (`vendor_to_customer`, private). All windows and limits are in `config/eventapp.php` → `reviews`.

- **Double-blind.** A review is `pending` and hidden until the other side has reviewed too, or 14 days have passed; then the booking's reviews are published together. `GET bookings/{id}/reviews` returns `mine`, `can_review`, `review_until` and `other_side: { submitted, review }`; `review` stays null until it's published, and the customer never sees the vendor's review of them.
- **Edits**: by the author for 48 hours, and only while pending. **Replies**: owners and managers post one public reply per published review (`POST reviews/{id}/reply` again edits it, for 48 hours).
- **Private customer reviews** (`GET customers/{id}/vendor-reviews`) are shown only to the teams of vendors the customer currently has a `pending_vendor` or `accepted` booking with. They never appear on the customer's profile.
- **Lists** are published reviews only, 20 per page, with a `summary` (`average`, `count`, `stars`, `aspects`) on the first page. Every review carries `verified_booking: true` and `booking: { event_type, event_date }`.
- **Ratings**: `vendors.rating_*` and `items.rating_*` (items listed in a review's `item_ids`) are recomputed from published reviews whenever one is published, removed or restored.
- **Removal** happens only through reports (`POST reviews/{id}/report` with `abuse`, `private_details` or `spam`, into the `reports` queue) and the admin panel, never for being negative. A removed review keeps its reason, note and who removed it, and can be restored.
- **Scheduled**: `reviews:sweep` (hourly) publishes reviews past the blind window and reminds whoever hasn't reviewed, 1 and 7 days after completion (`settings.notify.reviews: false` turns these off). Notifications: `review_received`, `vendor_replied`, `review_reminder`.
- **Trust signals**: `vendors:trust-signals` (nightly) caches `response_time_minutes` (median to accept/decline), `acceptance_rate` and `cancellation_rate` (vendor-cancelled, last 12 months) on vendors. `VendorResource` returns them as `trust`, with `cancellation_rate` only above `eventapp.trust.show_cancellation_rate_above`.

## Vendor plans

Plans (`starter`, `growth`, `pro`, `business`) are rows in `plans`, inserted by the migration and edited in `/admin/plans`: prices in minor units (yearly = 10 × monthly), `limits` (`items`, `packages`, `team`; null = unlimited), `features` (analytics level, web dashboard, …), `commission_percent`, `visibility_level` and `featured_weeks_per_month`. A vendor without a current subscription is on Starter. Rules that aren't per plan (trial length, grace, reminders, boosts) are in `config/eventapp.php` → `subscriptions`, `visibility`, `analytics`.

- **Entitlements** (`Subscriptions\Services\Entitlements`) answer `canAddItem` / `canAddPackage` / `canAddMember` / `hasFeature`, limits and usage. Adding past a limit returns **403** `{ code: "plan_limit", resource, limit, used, upgrade_to }`. Going over a limit (after a downgrade) never deletes or hides anything: existing items, packages, members, bookings, reviews and posts stay as they are; only adding more is blocked. Verification, reviews, ratings and deposit protection never depend on the plan. The vendor web dashboard needs the `web_dashboard` feature (Growth and up).
- **Trial**: one 30-day Pro trial per vendor, no payment. **Upgrades** apply once paid and cost the new price minus the unused days of the current period (prorated by whole days, rounded to minor units). **Downgrades and cancellations** take effect at the period end (`scheduled_change`, `cancel_at_period_end`; `resume` undoes them). Only owners change plans or pay; managers can see the plan, usage and analytics.
- **Payments** go through `PaymentService::startStandalone` (purpose `subscription`, no checkout) and Paystack's hosted page; the payment webhook marks it paid and `PaymentSucceeded` activates or extends the period in the same transaction, once (ledger type `subscription`). Cards are saved (reusable authorization) and renewed automatically a few hours before the period ends; mobile money is prepaid, so owners get reminders 3 and 1 days before with a pay link. Unpaid at the end: **grace** for 3 days (benefits continue, `banner` in the subscription response), then Starter.
- **Commission**: each booking snapshots its vendor's plan `commission_percent` at checkout.
- **Visibility**: a paid plan's `visibility_boost` (≤ 0.1, cached on `vendors`) counts the vendor up to 10% closer in nearby search, lifts Discover scores by up to 10% and adds a small `plan` component (weight 0.05) to suggestions, so a clearly better match still wins. **Featured weeks** (granted monthly for paid Pro/Business) are spent on a week starting Monday; while it runs the vendor may appear in `sponsored` (labelled, separate from the results) in `GET vendors?lat=&lng=&event_type=&date=` and `GET events/{id}/needs`, only when it matches the event type, area and date.
- **Analytics** returns only the metrics of the plan's level (`basic` → `standard` → `full` → `full_plus`), plus `locked` for an upgrade prompt.
- **Scheduled**: `subscriptions:sweep` (hourly) charges cards, sends reminders, starts grace, drops to Starter, applies scheduled downgrades and grants featured weeks. **Admin**: `/admin/subscriptions` (filters, extend with a reason, comp a plan, MRR / vendors by plan / churn this month).

## Local setup

Requirements: PHP 8.3+ with the `pdo_pgsql`, `intl` and `redis` extensions; Composer; PostgreSQL 15+ with **PostGIS 3**; Redis.

PostgreSQL with PostGIS, any of:

```bash
brew install postgresql@17 postgis && brew services start postgresql@17   # macOS
docker run -d --name petals-db -e POSTGRES_PASSWORD=secret -p 5432:5432 postgis/postgis:17-3.5
```

Create the database (`createdb event_app`) and set `DB_*` in `.env`. The first migration runs `CREATE EXTENSION IF NOT EXISTS postgis`, which needs a role allowed to create extensions (the default `postgres` user is).

```bash
composer install
cp .env.example .env
php artisan key:generate

# One-time package setup
php artisan horizon:install            # queue dashboard + config/horizon.php
php artisan install:broadcasting --reverb   # real-time chat later; answer "no" to the frontend prompts

php artisan migrate
php artisan db:seed        # users, vendors, an event, categories and demo items

php artisan serve          # API on http://localhost:8000/v1
php artisan queue:work --queue=default,media,recommendations   # or: php artisan horizon
php artisan schedule:work  # hold sweeper, completions, feed ranking, reviews, trust signals, plans
```

Seeded logins (local only; OTP is always `123456` and no SMS is sent):

| Who | App (phone) | Dashboard |
| --- | --- | --- |
| Platform admin | +233200000001 | `/admin` — admin@eventapp.test / password |
| Vendor owner (Royal Canopies) | +233200000002 | `/vendor` — vendor@eventapp.test / password |
| Host with a wedding | +233200000003 | — |

Payments locally use the fake gateway: `POST /v1/checkouts/{id}/pay` returns a payment id; complete it with `POST /v1/dev/payments/{payment}/succeed`.

## Tests

```bash
php artisan test
```

The suite runs on SQLite in memory by default, with the real SQL stock ledger and catalog (location matching uses a bounding box + haversine there instead of PostGIS). It covers sign-in and token rotation, vendor roles, the full booking lifecycle through payout, all-or-nothing checkout, overselling and the reservation rules, quantity-change warnings, variant deactivation, blocks, service-area matching, hold expiry, refunds, feeds, shop-tag approval, likes, comments, ranking and both dashboards.

Run the same suite on PostgreSQL + PostGIS (use a throwaway database; tests reset it):

```bash
createdb event_app_test
DB_CONNECTION=pgsql DB_DATABASE=event_app_test DB_USERNAME=postgres DB_PASSWORD=secret php artisan test
```

## Deploying: Forge on EC2

The server stays stateless: data lives in managed services, so a server can be replaced at any time.

1. **Managed data** in one AWS region close to your users:
   - PostgreSQL 15+ on **Amazon RDS** (automated backups on). RDS ships PostGIS; enable it once, as the master user, before the first deploy:
     ```sql
     CREATE EXTENSION IF NOT EXISTS postgis;
     ```
     (The migration also runs this, but the app's database user usually lacks the `rds_superuser` rights it needs.)
   - **Cloudflare R2 + Stream** for media (next section). Redis on the server is fine to start; move to ElastiCache when you add a second server.
2. **Forge**: connect your AWS account, create an app server (2 vCPU / 4 GB is plenty to start) with PHP 8.3+ (Forge's PHP includes `pdo_pgsql`).
3. **Site**: point it at your Git repository, set the environment from `.env.example` with production values (`APP_ENV=production`, `APP_DEBUG=false`, `DB_CONNECTION=pgsql` with the RDS credentials and `DB_SSLMODE=require`, `PAYMENT_GATEWAY=paystack`, the `R2_*`, `MEDIA_CDN_URL` and `CLOUDFLARE_*` media settings), and enable HTTPS with Let's Encrypt.
4. **Deploy script** (Forge's default, plus):
   ```bash
   php artisan migrate --force
   php artisan optimize
   php artisan filament:optimize
   php artisan horizon:terminate
   ```
5. **Daemons**: `php artisan horizon` and, when chat ships, `php artisan reverb:start`. **Scheduler**: enable it in Forge (runs `schedule:run` every minute).

   **Horizon queues.** Jobs use three queues: `default` (notifications, feed fan-out), `media` (image processing and media deletion: CPU-heavy, give it its own supervisor with 2–4 processes and `'timeout' => 180`) and `recommendations` (vendor suggestions: the daily `recommendations:run` fan-out, its notifications and booking attribution). Give `recommendations` its own supervisor in `config/horizon.php` with a low process count (1–2) and `'balance' => false`, so a big daily run never delays the `default` queue the API depends on:

   ```php
   'supervisor-recommendations' => [
       'connection' => 'redis',
       'queue' => ['recommendations'],
       'maxProcesses' => 2,
       'tries' => 3,
       'timeout' => 120,
   ],
   ```
   ```php
   'supervisor-media' => [
       'connection' => 'redis',
       'queue' => ['media'],
       'maxProcesses' => 3,
       'tries' => 3,
       'timeout' => 180,
       'memory' => 512, // decoding a 15 MB HEIC needs room
   ],
   ```

   The server needs the `imagick` PHP extension with HEIC support (`sudo apt install php8.5-imagick libheif1`), or set `MEDIA_IMAGE_DRIVER=gd` (no HEIC).
6. **Paystack**: set the webhook URL to `https://your-domain/v1/webhooks/payments`.
7. **Media: Cloudflare R2 and Stream.**
   - **R2 bucket** (R2 → Create bucket, e.g. `petals-media`), left **private**. Create an R2 API token with *Object Read & Write* on that bucket and set `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET` and `R2_ENDPOINT=https://<account_id>.r2.cloudflarestorage.com`.
   - **CORS** on the bucket so the app (and any web client) can PUT directly:
     ```json
     [{ "AllowedOrigins": ["*"], "AllowedMethods": ["PUT"], "AllowedHeaders": ["content-type", "content-length"], "ExposeHeaders": ["ETag"], "MaxAgeSeconds": 3600 }]
     ```
   - **CDN**: bucket → Settings → *Custom domains* → connect e.g. `media.petalsinajar.com` (served and cached by Cloudflare). Set `MEDIA_CDN_URL=https://media.petalsinajar.com`. Only processed renditions live under `media/`; originals under `uploads/` are deleted after processing. To keep them unreachable even meanwhile, add a WAF rule on the custom domain blocking `starts_with(http.request.uri.path, "/uploads/")`.
   - **Stream**: enable Stream on the account, create an API token with *Stream: Edit*, and set `CLOUDFLARE_ACCOUNT_ID` and `CLOUDFLARE_STREAM_TOKEN`. Copy the customer subdomain (Stream → any video → embed) into `CLOUDFLARE_STREAM_DELIVERY_URL=https://customer-<code>.cloudflarestream.com`.
   - **Stream webhook**: register `https://your-domain/v1/webhooks/video` once:
     ```bash
     curl -X PUT "https://api.cloudflare.com/client/v4/accounts/$CLOUDFLARE_ACCOUNT_ID/stream/webhook" \
       -H "Authorization: Bearer $CLOUDFLARE_STREAM_TOKEN" -d '{"notificationUrl":"https://your-domain/v1/webhooks/video"}'
     ```
     The response's `secret` goes in `CLOUDFLARE_STREAM_WEBHOOK_SECRET`.

## What's next

The design doc's later phases are not built yet: messaging (Reverb), idea boards, stories, quotes and invoices for corporate clients, multi-vendor packages in the cart, payout batches, push notifications (FCM) and a real SMS provider. Add each as a new module under `app/Modules`, following the same layout.
