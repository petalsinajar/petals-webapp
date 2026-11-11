# Petalsinajar API — guide for coding assistants

Laravel 13 + Filament 5 backend for a social events app with built-in rentals. Read README.md first.

## Architecture rules

1. **Modules.** Code lives in `app/Modules/<Module>/{Models,Http/Controllers,Http/Resources,Services,Policies,Enums,Jobs,Notifications,Console}`. A new feature is a new module or a clear extension of one. Modules call each other through services and small interfaces (`Catalog\Contracts\CatalogReader`, `Catalog\Availability\AvailabilityStore`, `Payments\Contracts\PaymentGateway`), never through another module's controllers.
2. **One database.** Everything, inventory included, is PostgreSQL with PostGIS. Stock and bookings commit together: `CheckoutService` reserves and writes the checkout, bookings and lines in one `DB::transaction`. Other modules still read inventory only through `CatalogReader`/`AvailabilityStore`, never the Catalog tables. Location matching goes through `App\Support\Geo` (PostGIS on pgsql, bounding box + haversine elsewhere). Anything driver-specific must also work on SQLite, which the tests use.
3. **Stock only changes through `AvailabilityStore`** (`SqlAvailabilityStore`). Never write `availability_days` or `reservations` directly. Reserving is all-or-nothing (a guarded `UPDATE` per variant-day, touched in `(variant_id, day)` order), and releases and confirms are idempotent. Variants that bookings, reservations, packages or posts point at are deactivated, never deleted (`ItemVariant` enforces it).
4. **Booking status only changes through `BookingService::transition()`** (or a method that calls it). It locks the row, checks `BookingStatus::canMoveTo()`, records `booking_status_events`, releases stock and notifies.
5. **Money** is integer minor units in `*_minor` columns plus a `currency`. Use `App\Support\Money`. Ledger rows (`ledger_entries`) are append-only; use `LedgerEntry::record()` with an idempotency key.
6. **Authorization** goes through policies (`VendorPolicy` operate/manage/own, `EventPolicy`, `BookingPolicy`), checked against `vendor_members` on every request.
7. **API shape**: routes in `routes/api.php` under `/v1`; snake_case keys; single objects unwrapped; lists `{ data, next_cursor }` with cursor pagination; validation errors `{ message, errors }`. Booking actions are verbs, not a generic status PATCH.
8. **IDs**: all ids are ULIDs (categories use a slug), with real foreign keys. Variants are rows in `item_variants`; clients send a variant's `id` back when editing an item's variants, and leave it out for new ones.
9. **Polymorphic types** use the morph map in `AppServiceProvider` (`user`, `vendor`, `post`, `comment`).
10. **Media** (images, video) is uploaded straight to storage through `POST /v1/uploads` and referenced by id. Never accept file URLs or files in other endpoints: take media ids, check them with `Media\Services\MediaLibrary::resolve()`, then `attach()` and `releaseExcept()`. Store keys, never URLs; responses use `Media::toPayload()`.
11. **New models** mirror their column defaults in `$attributes` so freshly created models are complete before a refresh.
12. **Reviews** change status only through `Reviews\Services\ReviewService` (submit, publish) and `ReviewModeration` (remove, restore). `vendors.rating_*` / `items.rating_*` are recomputed by `RatingAggregator`, never incremented. Vendor → customer reviews are private: never return them outside `customers/{id}/vendor-reviews`.

## Working

- Run `php artisan test` before saying a task is done. Tests use SQLite in memory with the real stock ledger and catalog (create inventory with `$this->variant()` or the Item/ItemVariant factories); they must also pass on PostgreSQL (`DB_CONNECTION=pgsql DB_DATABASE=event_app_test php artisan test`). Add a feature test for every new endpoint and a unit test for new domain rules.
- Run `./vendor/bin/pint` to format.
- Add migrations in `database/migrations`. Until the first deploy, edit the existing migrations in place. PostgreSQL-only DDL (PostGIS) goes behind `DB::getDriverName() === 'pgsql'`.
- Config for business rules lives in `config/eventapp.php`; don't hard-code fees, windows or limits.
