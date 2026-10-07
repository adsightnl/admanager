<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingRuleStat extends Model
{
    public const NO_RULE = '(No pricing rule applied)';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'revenue' => 'decimal:4',
        ];
    }
}
