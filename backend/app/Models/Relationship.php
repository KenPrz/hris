<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RelationshipFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class Relationship extends Model
{
    /** @use HasFactory<RelationshipFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['id'];
    }
}
