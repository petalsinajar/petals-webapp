<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendors record bookings made outside the app (phone, walk-in). Those may have no app
 * customer and no event, so both become optional and the customer's contact is kept here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignUlid('event_id')->nullable()->change();
            $table->foreignUlid('customer_id')->nullable()->change();
            $table->string('customer_name', 120)->nullable()->after('customer_id');
            $table->string('customer_phone', 20)->nullable()->after('customer_name');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['customer_name', 'customer_phone']);
        });
    }
};
