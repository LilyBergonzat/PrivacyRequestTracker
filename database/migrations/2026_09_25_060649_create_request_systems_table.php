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
        Schema::create('request_systems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('privacy_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('system_id')->constrained()->restrictOnDelete();
            $table->dateTime('due_at');
            $table->string('status')->default('pending');
            $table->text('result_summary')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['privacy_request_id', 'system_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_systems');
    }
};
