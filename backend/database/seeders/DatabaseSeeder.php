<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * The RBAC catalog first (the 'HR Admin' role must exist before CompanySeeder assigns
     * it), then the Manila/Cebu company. See docs/05-rbac.md.
     */
    public function run(): void
    {
        $this->call([
            RbacSeeder::class,
            CompanySeeder::class,
        ]);
    }
}
