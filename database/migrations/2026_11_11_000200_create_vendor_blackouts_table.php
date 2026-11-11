<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Days a vendor takes no new bookings (holiday, fully committed, closed). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_blackouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on'); // inclusive
            $table->string('reason', 120)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['vendor_id', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_blackouts');
    }
};
