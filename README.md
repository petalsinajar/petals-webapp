# Petalsinajar API

Backend for Petalsinajar: a social app for weddings, birthdays and corporate events, with built-in rentals. Hosts create events and post photos; anyone can rent the chairs, tents, decor and sound they see in posts; vendors run their business from the same app or a web dashboard.

- **Laravel 13** REST API under `/v1` for the Flutter app (Sanctum bearer tokens)
- **Filament 5** dashboards: `/admin` (platform team) and `/vendor/{handle}` (vendors, multi-tenant)
- **PostgreSQL** for accounts, events, bookings, money and social data
- **MongoDB** for inventory only: categories, items, packages, and the stock ledger

## How it's organised

One Laravel app split into modules. Each module owns its models, HTTP layer and services; modules talk through small interfaces, not each other's internals.

```
app/
  Modules/
    Identity/   users, vendors, vendor_members, @handles, phone OTP, tokens
    Catalog/    MongoDB items/variants/packages + Availability (the stock ledger)
    Events/     events, guests, RSVPs
    Bookings/   checkout, bookings, lifecycle, hold sweeper, completion
    Payments/   payment gateway (fake | Paystack), payments, append-only ledger, refunds
    Social/     posts with shop tags, follows, reactions, comments, feeds, notifications
    Recommendations/  vendor suggestions for hosts' upcoming events (daily, queued)
  Filament/
    Admin/      users, vendor verification, bookings & disputes, post moderation
    Vendor/     inventory (MongoDB), booking schedule with accept/decline/dispatch/return
  Support/      Money (minor units), Actor (user|vendor), PhoneNumber, Paginated
```

Rules that hold everywhere:

1. **Money is integer minor units** (`*_minor`, pesewas for GHS). Never floats.
2. **Stock is quantity over time.** Each variant has a quantity; reservations take part of it for a date window. Checking and reserving happen in one MongoDB transaction, so the last 50 chairs can't be sold twice.
3. **Bookings snapshot what was sold**: titles, unit prices and the vendor's cancellation policy are copied at booking time.
4. **Vendors are social actors.** Posts, follows and comments have an author that is a user or a vendor (`{type, id}`).
5. **One account, many roles.** A user can belong to several vendors as `owner`, `manager` or `staff`, checked on every request.

## Booking across two databases

Stock lives in MongoDB and bookings in PostgreSQL, so a booking can't be one transaction. `CheckoutService` does it in a safe order:

1. Build every booking and generate its id.
2. Reserve all stock in MongoDB as **holds** carrying those ids (all-or-nothing across vendors).
3. Write the checkout and bookings to PostgreSQL.
4. If step 3 fails, release the holds. If the process dies in between, the holds expire and `inventory:release-expired-holds` frees them.

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
| Vendors | `POST vendors`, `GET/PATCH vendors/{id}`, `vendors/{id}/members`, `vendors/{id}/items`, `vendors/{id}/calendar`, `vendors/{id}/blocks`, `vendors/{id}/bookings`, `vendors/{id}/post-tags` |
| Catalog | `categories`, `items?category=&event_type=&q=&lat=&lng=`, `items/{id}`, `items/{id}/availability`, `items/{id}/posts`, `packages/{id}` |
| Events | `POST events`, `events/{id}`, `events/{id}/members`, `PUT events/{id}/rsvp`, `events/{id}/posts`, `events/{id}/bookings`, `events/{id}/suggestions` |
| Suggestions | `POST suggestions/{id}/open`, `POST suggestions/{id}/dismiss`, `vendors/{id}/recommendation-stats?days=` |
| Checkout | `POST checkouts`, `GET checkouts/{id}`, `POST checkouts/{id}/pay`, `POST webhooks/payments` |
| Social | `feed/following`, `feed/discover?event_type=`, `posts`, `posts/{id}/reaction`, `posts/{id}/comments`, `posts/{id}/shop-taps`, `follows`, `notifications` |

## Local setup

Requirements: PHP 8.3+ with the `mongodb`, `pdo_pgsql`, `intl` and `redis` extensions; Composer; PostgreSQL; Redis; MongoDB **running as a replica set** (transactions need it).

```bash
composer install
cp .env.example .env
php artisan key:generate

# One-time package setup
php artisan horizon:install            # queue dashboard + config/horizon.php
php artisan install:broadcasting --reverb   # real-time chat later; answer "no" to the frontend prompts

php artisan migrate
php artisan migrate --database=mongodb --path=database/migrations/mongodb
php artisan db:seed
php artisan db:seed --class=CatalogSeeder

php artisan serve          # API on http://localhost:8000/v1
php artisan queue:work --queue=default,recommendations   # or: php artisan horizon
php artisan schedule:work  # hold sweeper, completions, feed ranking
```

A single-node local MongoDB replica set: start `mongod --replSet rs0`, then run `rs.initiate()` once in `mongosh`. A free MongoDB Atlas cluster works too.

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

The suite runs on SQLite in memory with inventory in memory (`INVENTORY_DRIVER=memory`), so MongoDB isn't needed. It covers sign-in and token rotation, vendor roles, the full booking lifecycle through payout, all-or-nothing checkout, overselling, hold expiry, refunds, feeds, shop-tag approval, likes, comments, ranking and both dashboards.

To check the production MongoDB stock ledger against a real replica set:

```bash
MONGODB_TEST_URI="mongodb://127.0.0.1:27017/?replicaSet=rs0" php artisan test --testsuite=Integration
```

## Deploying: Forge on EC2

The server stays stateless: data lives in managed services, so a server can be replaced at any time.

1. **Managed data** in one AWS region close to your users:
   - PostgreSQL on **Amazon RDS** (automated backups on).
   - **MongoDB Atlas** cluster hosted on AWS in the same region; allow the EC2 server's IP.
   - **S3** bucket (plus CloudFront) for media. Redis on the server is fine to start; move to ElastiCache when you add a second server.
2. **Forge**: connect your AWS account, create an app server (2 vCPU / 4 GB is plenty to start) with PHP 8.3+, and install the `mongodb` PHP extension on it (`sudo pecl install mongodb`, then enable it for FPM and CLI).
3. **Site**: point it at your Git repository, set the environment from `.env.example` with production values (`APP_ENV=production`, `APP_DEBUG=false`, RDS and Atlas credentials, `PAYMENT_GATEWAY=paystack`, `FILESYSTEM_DISK=s3`), and enable HTTPS with Let's Encrypt.
4. **Deploy script** (Forge's default, plus):
   ```bash
   php artisan migrate --force
   php artisan migrate --force --database=mongodb --path=database/migrations/mongodb
   php artisan optimize
   php artisan filament:optimize
   php artisan horizon:terminate
   ```
5. **Daemons**: `php artisan horizon` and, when chat ships, `php artisan reverb:start`. **Scheduler**: enable it in Forge (runs `schedule:run` every minute).

   **Horizon queues.** Jobs use two queues: `default` (notifications, feed fan-out, stock release) and `recommendations` (vendor suggestions: the daily `recommendations:run` fan-out, its notifications and booking attribution). Give `recommendations` its own supervisor in `config/horizon.php` with a low process count (1–2) and `'balance' => false`, so a big daily run never delays the `default` queue the API depends on:

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
