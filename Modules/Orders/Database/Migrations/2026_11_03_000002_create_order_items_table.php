<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            // Same module, so a real foreign key is allowed.
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            // A plain number plus a snapshot: the order stays correct if the product changes or is deleted.
            $table->unsignedBigInteger('product_id');
            $table->string('product_name');
            $table->decimal('unit_price', 10, 2);
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
