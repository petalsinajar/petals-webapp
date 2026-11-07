<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_id')->constrained('users');
            $table->foreignUlid('event_id')->constrained();
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->string('currency', 3);
            $table->string('status', 20)->default('open'); // open | partially_paid | paid | expired | cancelled
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('number', 20)->unique(); // BK-26-004812
            $table->foreignUlid('event_id')->constrained();
            $table->foreignUlid('checkout_id')->nullable()->constrained();
            $table->foreignUlid('customer_id')->constrained('users');
            $table->foreignUlid('vendor_id')->constrained();
            $table->string('source', 20)->default('app'); // app | quote | vendor_manual
            $table->timestamp('window_start');            // delivery to pickup, before buffers
            $table->timestamp('window_end');
            $table->json('fulfilment');                   // type, address, lat, lng, setup, deliver_at, collect_at, contact_phone
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('delivery_minor')->default(0);
            $table->unsignedBigInteger('setup_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('service_fee_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('deposit_due_minor');
            $table->unsignedBigInteger('damage_deposit_minor')->default(0);
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->string('currency', 3);
            $table->decimal('commission_percent', 5, 2);
            $table->unsignedBigInteger('commission_minor');
            $table->json('policy_snapshot');              // policies copied at booking time
            $table->string('status', 30);
            $table->timestamp('vendor_respond_by')->nullable();
            $table->timestamp('pay_by')->nullable();
            $table->json('notes')->nullable();            // customer, vendor_internal
            $table->json('condition')->nullable();        // delivered/returned photos, damage claim
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'status', 'window_start']);
            $table->index(['customer_id', 'created_at']);
            $table->index('event_id');
        });

        Schema::create('booking_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->string('item_id', 24);       // MongoDB ObjectId
            $table->string('variant_id', 24);    // MongoDB ObjectId
            $table->string('package_id', 24)->nullable();
            $table->string('reservation_id', 24)->nullable(); // MongoDB reservation holding the stock
            $table->string('title_snapshot', 200);
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->timestamps();

            $table->index('item_id');
        });

        Schema::create('booking_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at');

            $table->index(['booking_id', 'created_at']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('checkout_id')->constrained();
            $table->foreignUlid('payer_id')->constrained('users');
            $table->string('purpose', 20); // deposit | balance | full
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->json('allocations');   // { booking_id: amount_minor }
            $table->string('provider', 20);
            $table->string('method', 20)->nullable(); // momo | card | bank
            $table->string('provider_ref')->unique();
            $table->string('idempotency_key')->unique();
            $table->string('status', 20)->default('pending'); // pending | succeeded | failed | refunded | partially_refunded
            $table->json('raw')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        // Append-only. Balances are sums over this table.
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('booking_id')->nullable()->constrained();
            $table->foreignUlid('vendor_id')->nullable()->constrained();
            $table->foreignUlid('payment_id')->nullable()->constrained();
            // customer_payment | refund | commission | vendor_earning | payout | damage_hold | damage_release | adjustment
            $table->string('type', 30);
            $table->bigInteger('amount_minor'); // signed
            $table->string('currency', 3);
            $table->string('memo')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamp('created_at');

            $table->index(['booking_id', 'type']);
            $table->index(['vendor_id', 'created_at']);
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_id')->constrained();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->json('booking_ids');
            $table->string('method', 20)->nullable();
            $table->string('provider_ref')->nullable();
            $table->string('status', 20)->default('scheduled'); // scheduled | processing | paid | failed
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('booking_status_events');
        Schema::dropIfExists('booking_lines');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('checkouts');
    }
};
