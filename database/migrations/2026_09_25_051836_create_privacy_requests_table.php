<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('privacy_requests', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 16)->unique();
            $table->string('type');
            $table->string('channel');
            $table->string('status')->default('received');
            $table->dateTime('received_at');
            $table->dateTime('due_at');
            $table->dateTime('extended_until')->nullable();
            $table->text('extension_reason')->nullable();
            $table->timestamp('extension_notified_at')->nullable();
            $table->text('identity_verification_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('contact_method');
            $table->text('contact_details')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->date('purge_after')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('privacy_requests');
    }
};
