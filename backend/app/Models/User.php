<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Task 2 note: this trait (plus the two overrides below) is the minimal fix needed
    // to keep users.id usable as a real, retrievable uuid now that the users migration
    // creates it as uuid (see migration 0001_01_01_000000). Without it, Eloquent's
    // default $keyType='int'/$incrementing=true corrupts every generated id — e.g. a
    // uuid of "019f8e18-fbe8-..." gets (int)-cast down to 19 the moment it round-trips
    // through insertGetId(), silently breaking every FK that points at users.id. This is
    // NOT the full Task 3 uuid wiring: Sanctum's personal_access_tokens morph key and
    // spatie's model_has_roles morph key are untouched (neither package's migrations are
    // published yet), and no auth/role behavior changes. Task 3 should treat this as
    // already done and build the rest on top.
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
