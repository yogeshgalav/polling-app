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
                ->foreignId("user_id")
                ->nullable()
                ->constrained("users")
                ->nullOnDelete();
            $table->string("device_id")->nullable();
            $table->string("ip_address");
            $table->timestamps();

            $table->unique(["poll_id", "user_id"]);
            $table->unique(["poll_id", "device_id"]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("votes");
    }
};
