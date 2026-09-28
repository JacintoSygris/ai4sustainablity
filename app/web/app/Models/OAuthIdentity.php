<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OAuthIdentity extends Model
{
    protected $table = 'oauth_identities';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'provider',
        'issuer',
        'subject',
    ];

    protected static function booted(): void
    {
        static::saving(function (OAuthIdentity $identity): void {
            $identity->provider = mb_strtolower(trim((string) $identity->provider));
            $identity->issuer = trim((string) $identity->issuer);
            $identity->subject = trim((string) $identity->subject);
            $identity->identity_hash = self::hashFor(
                $identity->provider,
                $identity->issuer,
                $identity->subject,
            );
        });
    }

    public static function hashFor(string $provider, string $issuer, string $subject): string
    {
        return hash('sha256', mb_strtolower(trim($provider))."\0".trim($issuer)."\0".trim($subject));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
