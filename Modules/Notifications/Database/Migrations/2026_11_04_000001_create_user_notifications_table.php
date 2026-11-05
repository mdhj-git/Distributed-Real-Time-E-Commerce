<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Named user_notifications because "notifications" belongs to Laravel's built-in system.
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            // A plain number: no foreign key across module boundaries (the Users module owns users).
            $table->unsignedBigInteger('user_id');
            $table->string('type', 50);
            $table->string('title');
            $table->string('message', 500);
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
