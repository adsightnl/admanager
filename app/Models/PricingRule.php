<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PricingRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'floor' => 'decimal:4',
            'floor_is_override' => 'boolean',
        ];
    }

    public function partner(): ?string
    {
        return config('pricing.partners.'.$this->prefix);
    }

    public function stats(): HasMany
    {
        return $this->hasMany(PricingRuleStat::class, 'rule_name', 'name');
    }

    public function floorChanges(): HasMany
    {
        return $this->hasMany(PricingRuleFloorChange::class);
    }

    /**
     * Derive prefix and floor from a rule name such as "AS_0.30_mobile" or "YIT_0.10".
     *
     * @return array{prefix: ?string, floor: ?float}
     */
    public static function parseName(string $name): array
    {
        if (preg_match('/^([A-Za-z]+)_(\d+(?:\.\d+)?)/', $name, $m)) {
            return ['prefix' => $m[1], 'floor' => (float) $m[2]];
        }

        return ['prefix' => null, 'floor' => null];
    }
}
