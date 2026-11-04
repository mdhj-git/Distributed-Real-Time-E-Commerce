<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Internal id that ties the order to its stock reservation. Never exposed in any response.
            $table->char('reference', 36)->unique();
            // A plain number: no foreign key across module boundaries (the Users module owns users).
            $table->unsignedBigInteger('user_id');
            $table->string('status', 20)->default('pending');
            $table->decimal('total', 10, 2);
            $table->timestamps();

            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
