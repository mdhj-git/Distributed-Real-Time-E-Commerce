<?php

namespace Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotification extends Model
{
    public const TYPE_ORDER_PLACED = 'order.placed';

    public const TYPE_ORDER_STATUS_CHANGED = 'order.status_changed';

    protected $table = 'user_notifications';

    protected $fillable = ['user_id', 'type', 'title', 'message', 'data', 'read_at'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }
}
