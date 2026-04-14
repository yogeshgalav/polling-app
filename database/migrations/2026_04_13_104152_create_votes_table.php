<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create("votes", function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId("poll_id")
                ->constrained("polls")
                ->cascadeOnDelete();
            $table
                ->foreignId("poll_option_id")
                ->constrained("poll_options")
                ->cascadeOnDelete();
            $table
                ->foreignId("guest_id")
                ->nullable()
                ->constrained("guests")
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(["poll_id", "guest_id"]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("votes");
    }
};
