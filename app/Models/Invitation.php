<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Invitation extends Model
{
    public const VALID_DAYS = 7;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Create (or renew) the invitation for an email address.
     *
     * @return array{0: self, 1: string} The invitation and the plain token for the link.
     */
    public static function issue(string $email, ?User $by): array
    {
        $token = Str::random(48);

        $invitation = static::updateOrCreate(
            ['email' => strtolower($email)],
            [
                'token_hash' => hash('sha256', $token),
                'invited_by' => $by?->id,
                'expires_at' => now()->addDays(self::VALID_DAYS),
                'accepted_at' => null,
            ],
        );

        return [$invitation, $token];
    }

    public static function findByToken(string $token): ?self
    {
        return static::where('token_hash', hash('sha256', $token))->first();
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public function url(string $token): string
    {
        return route('invitations.accept', $token);
    }
}
