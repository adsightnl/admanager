<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceStat extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'revenue' => 'decimal:4',
            'ecpm' => 'decimal:4',
        ];
    }
}
