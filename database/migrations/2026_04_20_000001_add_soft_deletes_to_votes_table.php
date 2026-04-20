<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('votes', function (Blueprint $table) {
            // MySQL may rely on the existing composite unique index for FK lookups.
            // Ensure standalone indexes exist before dropping the unique constraint.
            $table->index('poll_id');
            $table->index('guest_id');
            $table->dropUnique('votes_poll_id_guest_id_unique');
            $table->softDeletes();
            $table->unique(['poll_id', 'guest_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('votes', function (Blueprint $table) {
            $table->dropUnique('votes_poll_id_guest_id_deleted_at_unique');
            $table->dropSoftDeletes();
            $table->unique(['poll_id', 'guest_id']);
        });
    }
};

