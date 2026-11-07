<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pre-launch sign-ups from the landing page. One row per number per role,
        // so someone can be on the list as both a host and a vendor.
        Schema::create('waitlist_signups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 80);
            $table->string('whatsapp_phone', 20);
            $table->string('city', 80);
            $table->string('role', 10);                      // host | vendor
            $table->string('business_name', 120)->nullable(); // vendors only
            $table->string('category', 40)->nullable();       // vendors only, a catalog category slug
            $table->json('event_types')->nullable();          // ["wedding","birthday","corporate"]
            $table->json('source')->nullable();               // utm_source, utm_medium, utm_campaign
            $table->timestamps();

            $table->unique(['whatsapp_phone', 'role']);
            $table->index(['role', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_signups');
    }
};
