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
  Filament/
    Admin/      users, vendor verification, bookings & disputes, post moderation
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

| Area | Main routes |
| --- | --- |
| Auth & me | `auth/otp`, `auth/verify`, `auth/refresh`, `auth/logout`, `GET/PATCH me`, `me/events`, `me/bookings`, `me/devices`, `me/dashboard-credentials` |
| Vendors | `vendors?lat=&lng=&category=&event_type=` (serving that venue, nearest first), `POST vendors`, `GET/PATCH vendors/{id}`, `vendors/{id}/members`, `vendors/{id}/items`, `vendors/{id}/calendar`, `vendors/{id}/blocks`, `vendors/{id}/bookings`, `vendors/{id}/post-tags` |
| Catalog | `categories`, `items?category=&event_type=&q=&lat=&lng=` (lat/lng: vendors whose own radius covers the point), `items/{id}`, `items/{id}/availability`, `items/{id}/posts`, `packages/{id}` |
| Events | `POST events`, `events/{id}`, `events/{id}/members`, `PUT events/{id}/rsvp`, `events/{id}/posts`, `events/{id}/bookings`, `events/{id}/suggestions` |
| Suggestions | `POST suggestions/{id}/open`, `POST suggestions/{id}/dismiss`, `vendors/{id}/recommendation-stats?days=` |
| Checkout | `POST checkouts`, `GET checkouts/{id}`, `POST checkouts/{id}/pay`, `POST webhooks/payments` |
| Social | `feed/following`, `feed/discover?event_type=`, `posts`, `posts/{id}/reaction`, `posts/{id}/comments`, `posts/{id}/shop-taps`, `follows`, `notifications` |

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
php artisan queue:work --queue=default,recommendations   # or: php artisan horizon
php artisan schedule:work  # hold sweeper, completions, feed ranking
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
   - **S3** bucket (plus CloudFront) for media. Redis on the server is fine to start; move to ElastiCache when you add a second server.
2. **Forge**: connect your AWS account, create an app server (2 vCPU / 4 GB is plenty to start) with PHP 8.3+ (Forge's PHP includes `pdo_pgsql`).
3. **Site**: point it at your Git repository, set the environment from `.env.example` with production values (`APP_ENV=production`, `APP_DEBUG=false`, `DB_CONNECTION=pgsql` with the RDS credentials and `DB_SSLMODE=require`, `PAYMENT_GATEWAY=paystack`, `FILESYSTEM_DISK=s3`), and enable HTTPS with Let's Encrypt.
4. **Deploy script** (Forge's default, plus):
   ```bash
   php artisan migrate --force
   php artisan optimize
   php artisan filament:optimize
   php artisan horizon:terminate
   ```
5. **Daemons**: `php artisan horizon` and, when chat ships, `php artisan reverb:start`. **Scheduler**: enable it in Forge (runs `schedule:run` every minute).

   **Horizon queues.** Jobs use two queues: `default` (notifications, feed fan-out) and `recommendations` (vendor suggestions: the daily `recommendations:run` fan-out, its notifications and booking attribution). Give `recommendations` its own supervisor in `config/horizon.php` with a low process count (1–2) and `'balance' => false`, so a big daily run never delays the `default` queue the API depends on:

   ```php
   'supervisor-recommendations' => [
       'connection' => 'redis',
       'queue' => ['recommendations'],
       'maxProcesses' => 2,
       'tries' => 3,
       'timeout' => 120,
   ],
   ```
6. **Paystack**: set the webhook URL to `https://your-domain/v1/webhooks/payments`.

## What's next

The design doc's later phases are not built yet: messaging (Reverb), reviews, idea boards, stories, quotes and invoices for corporate clients, multi-vendor packages in the cart, payout batches, push notifications (FCM) and a real SMS provider. Add each as a new module under `app/Modules`, following the same layout.
