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
        Schema::table('privacy_requests', function (Blueprint $table) {
            $table->after('contact_details', function (Blueprint $table) {
                $table->timestamp('notified_at')->nullable();
                $table->string('notification_outcome')->nullable();
                $table->text('notification_notes')->nullable();
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('privacy_requests', function (Blueprint $table) {
            $table->dropColumn('notified_at');
            $table->dropColumn('notification_outcome');
            $table->dropColumn('notification_notes');
        });
    }
};
