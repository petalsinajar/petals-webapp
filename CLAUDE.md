# Petalsinajar API — guide for coding assistants

Laravel 13 + Filament 5 backend for a social events app with built-in rentals. Read README.md first.

## Architecture rules

1. **Modules.** Code lives in `app/Modules/<Module>/{Models,Http/Controllers,Http/Resources,Services,Policies,Enums,Jobs,Notifications,Console}`. A new feature is a new module or a clear extension of one. Modules call each other through services and small interfaces (`Catalog\Contracts\CatalogReader`, `Catalog\Availability\AvailabilityStore`, `Payments\Contracts\PaymentGateway`), never through another module's controllers.
2. **Two databases.** Inventory (categories, items, packages, `availability_days`, `reservations`) is MongoDB via `mongodb/laravel-mongodb`; everything else is PostgreSQL. Never join across them: fetch ids from one side, batch-load from the other. Cross-database writes follow the order in `CheckoutService` (reserve in Mongo first, write SQL, release on failure, holds expire on their own).
3. **Stock only changes through `AvailabilityStore`.** Never write `availability_days` or `reservations` directly. Reserving is all-or-nothing and every write is idempotent.
4. **Booking status only changes through `BookingService::transition()`** (or a method that calls it). It locks the row, checks `BookingStatus::canMoveTo()`, records `booking_status_events`, releases stock and notifies.
5. **Money** is integer minor units in `*_minor` columns plus a `currency`. Use `App\Support\Money`. Ledger rows (`ledger_entries`) are append-only; use `LedgerEntry::record()` with an idempotency key.
6. **Authorization** goes through policies (`VendorPolicy` operate/manage/own, `EventPolicy`, `BookingPolicy`), checked against `vendor_members` on every request.
7. **API shape**: routes in `routes/api.php` under `/v1`; snake_case keys; single objects unwrapped; lists `{ data, next_cursor }` with cursor pagination; validation errors `{ message, errors }`. Booking actions are verbs, not a generic status PATCH.
8. **IDs**: ULIDs in SQL, ObjectId strings in MongoDB. Variants are embedded in items with a stable `variant_id`; clients must send it back when editing variants.
9. **Polymorphic types** use the morph map in `AppServiceProvider` (`user`, `vendor`, `post`, `comment`).
10. **New models** mirror their column defaults in `$attributes` so freshly created models are complete before a refresh.

## Working

- Run `php artisan test` before saying a task is done. Tests use SQLite in memory and in-memory inventory (`INVENTORY_DRIVER=memory`); add a feature test for every new endpoint and a unit test for new domain rules.
- Run `./vendor/bin/pint` to format.
- Add migrations for SQL in `database/migrations` and for MongoDB indexes in `database/migrations/mongodb`.
- Config for business rules lives in `config/eventapp.php`; don't hard-code fees, windows or limits.
