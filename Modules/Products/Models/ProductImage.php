<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    protected $fillable = [
        'product_id',
        'path',
        'position',
    ];

    /**
     * The single place the image disk is read. The module config decides it,
     * so the same value serves uploads and URLs.
     */
    public static function disk(): string
    {
        return config('products.image_disk', 'public');
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
