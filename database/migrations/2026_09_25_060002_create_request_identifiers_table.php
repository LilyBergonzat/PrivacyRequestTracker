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
        Schema::create('request_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('privacy_request_id')->constrained()->cascadeOnDelete();
            $table->string('identifier_type');
            $table->char('hmac', 64);
            $table->string('key_version', 16);
            $table->timestamps();

            $table->index(['key_version', 'hmac']);
            $table->unique(['privacy_request_id', 'identifier_type', 'hmac'], 'request_identifiers_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_identifiers');
    }
};
