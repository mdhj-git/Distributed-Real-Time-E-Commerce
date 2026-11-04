<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock now lives in the inventory service. The data of this table is NOT restored by down().
     *
     * The container entrypoint runs "php artisan migrate --force" on every start, so this migration
     * would drop the table before anyone ran inventory:import-stock. It therefore refuses to run
     * while the table still holds quantities, unless STOCK_LEVELS_DROP_CONFIRMED=true is set in .env
     * after "inventory:import-stock --verify" has passed (order of commands: see README).
     */
    public function up(): void
    {
        if (Schema::hasTable('stock_levels') && ! $this->dropConfirmed()) {
            $withStock = DB::table('stock_levels')->where('stock', '>', 0)->count();

            if ($withStock > 0) {
                throw new RuntimeException(
                    "stock_levels still holds quantities for {$withStock} product(s). Dropping it destroys that data. "
                    .'Run inventory:import-stock, then inventory:import-stock --verify (must pass), then set '
                    .'STOCK_LEVELS_DROP_CONFIRMED=true in .env and run the migration again.'
                );
            }
        }

        Schema::dropIfExists('stock_levels');
    }

    /**
     * Recreates the structure only (same as create_stock_levels_table). The data is gone for good.
     */
    public function down(): void
    {
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('stock')->default(0);
            $table->timestamps();
        });
    }

    private function dropConfirmed(): bool
    {
        return filter_var(env('STOCK_LEVELS_DROP_CONFIRMED', false), FILTER_VALIDATE_BOOLEAN);
    }
};
