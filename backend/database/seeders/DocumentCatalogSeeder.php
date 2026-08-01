<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Documents\Documentable;
use App\Models\Document;
use App\Models\DocumentCategory;
use Illuminate\Database\Seeder;

/**
 * A Philippine starter set for the document catalog.
 *
 * Unlike ProfileCatalogSeeder's identification categories — TIN, SSS and friends are fixed by
 * law and no UI creates them — this catalog is ADMIN-EDITABLE. These rows are a starting
 * point so the module is usable on first boot, not the whole of it.
 *
 * Idempotent throughout (updateOrCreate on `code`), which is what lets hris:bootstrap-admin
 * call it unconditionally.
 */
final class DocumentCatalogSeeder extends Seeder
{
    /** @var array<string, array{name: string, description: string}> */
    private const array CATEGORIES = [
        'PRE_EMPLOYMENT' => ['name' => 'Pre-employment', 'description' => 'Collected before an employee starts'],
        'STATUTORY' => ['name' => 'Statutory', 'description' => 'Required by law or a government agency'],
        'PERSONNEL' => ['name' => 'Personnel', 'description' => 'The employee 201 file'],
        'COMPANY' => ['name' => 'Company', 'description' => 'Documents belonging to the company or an office'],
    ];

    /**
     * @var array<int, array{
     *     code: string, name: string, description: string, category: string,
     *     applies_to: ?string, is_required: bool, validity_months: ?int
     * }>
     */
    private const array DOCUMENTS = [
        [
            'code' => 'NBI', 'name' => 'NBI Clearance',
            'description' => 'National Bureau of Investigation clearance',
            'category' => 'PRE_EMPLOYMENT',
            'applies_to' => 'employee', 'is_required' => true, 'validity_months' => 6,
        ],
        [
            'code' => 'MEDICAL', 'name' => 'Medical Certificate',
            'description' => 'Pre-employment medical examination result',
            'category' => 'PRE_EMPLOYMENT',
            'applies_to' => 'employee', 'is_required' => true, 'validity_months' => 12,
        ],
        [
            'code' => 'CONTRACT', 'name' => 'Employment Contract',
            'description' => 'Signed contract of employment',
            'category' => 'PERSONNEL',
            'applies_to' => 'employee', 'is_required' => true, 'validity_months' => null,
        ],
        [
            'code' => 'FILE_201', 'name' => '201 File',
            'description' => 'Miscellaneous personnel file contents',
            'category' => 'PERSONNEL',
            'applies_to' => 'employee', 'is_required' => false, 'validity_months' => null,
        ],
        [
            'code' => 'POLICY', 'name' => 'Company Policy',
            'description' => 'A policy document issued by the company',
            'category' => 'COMPANY',
            'applies_to' => null, 'is_required' => false, 'validity_months' => null,
        ],
        [
            'code' => 'BUSINESS_PERMIT', 'name' => 'Business Permit',
            'description' => 'Mayor\'s permit for the office location',
            'category' => 'COMPANY',
            'applies_to' => 'office', 'is_required' => true, 'validity_months' => 12,
        ],
    ];

    public function run(): void
    {
        $categoryIds = [];

        foreach (self::CATEGORIES as $code => $row) {
            $categoryIds[$code] = DocumentCategory::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $row['name'], 'description' => $row['description']],
            )->id;
        }

        foreach (self::DOCUMENTS as $row) {
            Document::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'category_id' => $categoryIds[$row['category']],
                    'applies_to' => $row['applies_to'] === null ? null : Documentable::from($row['applies_to']),
                    'is_required' => $row['is_required'],
                    'validity_months' => $row['validity_months'],
                ],
            );
        }
    }
}
