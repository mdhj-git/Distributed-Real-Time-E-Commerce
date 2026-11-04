<?php

namespace Modules\Products\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Products\Contracts\InventoryService;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;

class ProductsDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'electronics' => 'Electronics',
            'books' => 'Books',
            'clothing' => 'Clothing',
        ];

        foreach ($categories as $slug => $name) {
            Category::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }

        $products = [
            [
                'name' => 'Wireless Headphones',
                'description' => 'Over-ear wireless headphones with noise cancelling.',
                'price' => '79.99',
                'category' => 'electronics',
                'stock' => 50,
            ],
            [
                'name' => 'Cotton T-Shirt',
                'description' => 'Plain cotton t-shirt available in several sizes.',
                'price' => '15.00',
                'category' => 'clothing',
                'stock' => 100,
            ],
        ];

        $inventory = app(InventoryService::class);

        foreach ($products as $data) {
            $category = Category::query()->where('slug', $data['category'])->firstOrFail();

            $product = Product::updateOrCreate(
                ['name' => $data['name']],
                [
                    'category_id' => $category->id,
                    'description' => $data['description'],
                    'price' => $data['price'],
                ],
            );

            // Idempotent: a second run finds the target already reached.
            $current = $inventory->getStock([$product->id])[$product->id] ?? 0;

            if ($current !== $data['stock']) {
                $inventory->adjust($product->id, $data['stock'] - $current);
            }
        }
    }
}
