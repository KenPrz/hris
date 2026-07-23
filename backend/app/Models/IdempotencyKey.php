<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A stored response for a client-generated key. The key and the work it guards commit in
 * ONE transaction — EnsureIdempotency opens it. Ported from POS. See docs/01-architecture.md.
 */
final class IdempotencyKey extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public const ?string UPDATED_AT = null;

    protected $fillable = ['key', 'request_hash', 'response_code', 'response_body', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'response_code' => 'integer',
            'response_body' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
