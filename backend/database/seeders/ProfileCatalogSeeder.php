<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\EmployeeIdentificationCategory;
use App\Models\Relationship;
use Illuminate\Database\Seeder;

/**
 * Catalog data PRODUCTION needs, in the same category as RbacSeeder's permission catalog —
 * which is why hris:bootstrap-admin calls this and DatabaseSeeder is not the only caller.
 * DatabaseSeeder is dev-only (it pairs RbacSeeder with CompanySeeder's Manila/Cebu demo
 * company, which must never touch production).
 *
 * Idempotent throughout: updateOrCreate on `code`, so re-running a bootstrap is safe.
 */
final class ProfileCatalogSeeder extends Seeder
{
    /** @var array<int, array{code: string, name: string, description: string}> */
    private const array IDENTIFICATION_CATEGORIES = [
        ['code' => 'TIN', 'name' => 'TIN', 'description' => 'BIR Taxpayer Identification Number'],
        ['code' => 'SSS', 'name' => 'SSS ID', 'description' => 'Social Security System number'],
        ['code' => 'HDMF', 'name' => 'Pag-IBIG MID', 'description' => 'Home Development Mutual Fund number'],
        ['code' => 'PHIC', 'name' => 'PhilHealth', 'description' => 'PhilHealth Identification Number'],
        ['code' => 'BANK', 'name' => 'Bank Account', 'description' => 'Payroll bank account number'],
        ['code' => 'PASSPORT', 'name' => 'Passport Number', 'description' => 'DFA-issued passport number'],
        ['code' => 'DL', 'name' => "Driver's License", 'description' => 'LTO-issued licence number'],
        ['code' => 'PRC', 'name' => 'PRC License', 'description' => 'Professional Regulation Commission licence'],
    ];

    /** @var array<int, array{code: string, description: string}> */
    private const array RELATIONSHIPS = [
        ['code' => 'spouse', 'description' => 'Spouse'],
        ['code' => 'child', 'description' => 'Child'],
        ['code' => 'parent', 'description' => 'Parent'],
        ['code' => 'sibling', 'description' => 'Sibling'],
        ['code' => 'other', 'description' => 'Other'],
    ];

    public function run(): void
    {
        foreach (self::IDENTIFICATION_CATEGORIES as $row) {
            EmployeeIdentificationCategory::query()->updateOrCreate(
                ['code' => $row['code']],
                ['name' => $row['name'], 'description' => $row['description']],
            );
        }

        foreach (self::RELATIONSHIPS as $row) {
            Relationship::query()->updateOrCreate(
                ['code' => $row['code']],
                ['description' => $row['description']],
            );
        }
    }
}
