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
        Schema::create('step_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_system_id')->constrained()->cascadeOnDelete();
            $table->foreignId('system_step_id')->nullable()->constrained()->nullOnDelete();
            $table->string('step_title');
            $table->foreignId('completed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->unique(['request_system_id', 'system_step_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('step_completions');
    }
};
