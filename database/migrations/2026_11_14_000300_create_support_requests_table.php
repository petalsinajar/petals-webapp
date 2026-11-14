<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Messages from the public support page (/support), including account deletion requests. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('topic', 30);
            $table->string('name', 80);
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable(); // E.164
            $table->text('message');
            // The account the contact details match, if any. A hint for the team, not proof
            // of ownership: confirm through the account's own phone or email before acting.
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUlid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['resolved_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_requests');
    }
};
