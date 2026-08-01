<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Four actors x the nine document-catalog routes shipped across Tasks 5-7 (M10b-a). Every
 * expectation below is a deliberate policy decision, not an observation of what the code
 * happens to do — see DocumentPolicy::manageCatalog and the FormRequests under
 * app/Http/Requests/Documents, which each gate on it identically.
 *
 * Unlike ProfileScopeMatrixTest, the denial here is a plain 403, not 404: the catalog is
 * company-wide reference data with no owner id in the URL, so there is nothing for the
 * 404-not-403 enumeration guard (docs/05-rbac.md) to protect — the same shape as
 * /admin/pay-rules. See CreateDocumentRequest's docblock.
 *
 * `GET /documents/catalog` is deliberately included, not excluded like ProfileScopeMatrixTest
 * excludes `GET /profile/catalog` — it is ungated static reference data by design
 * (ShowCatalogController has no authorize() at all beyond auth:sanctum), and this row
 * documents that decision for every actor rather than hiding it.
 */
beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    $hrAdmin = User::factory()->create();
    $hrAdmin->assignRole('HR Admin');

    // Holds ONLY document.manage.self — never document.manage — so
    // DocumentPolicy::manageCatalog must deny it exactly like a stranger.
    // DocumentPolicyTest proves this at the policy level; this matrix proves it at the
    // route level, across every one of the eight admin routes at once.
    $selfOnly = User::factory()->create();
    $selfOnly->givePermissionTo('document.manage.self');

    $stranger = User::factory()->create();

    $this->actors = [
        'hr-admin' => $hrAdmin->fresh(),
        'self-only' => $selfOnly->fresh(),
        'stranger' => $stranger->fresh(),
        // Never assigned document.manage — reaches every route through Gate::before instead.
        'system-admin' => User::factory()->create(['is_system_admin' => true]),
    ];
});

/**
 * @return array<string, array{method: string, uri: string, payload: array<string, mixed>}>
 */
function documentCatalogMatrixRoutes(string $categoryId, string $documentCategoryId, string $documentId): array
{
    return [
        'GET /documents/catalog' => ['method' => 'getJson', 'uri' => '/api/v1/documents/catalog', 'payload' => []],
        'GET /admin/document-categories' => ['method' => 'getJson', 'uri' => '/api/v1/admin/document-categories', 'payload' => []],
        'POST /admin/document-categories' => ['method' => 'postJson', 'uri' => '/api/v1/admin/document-categories', 'payload' => [
            'code' => 'MTX_NEW_CATEGORY',
            'name' => 'Matrix New Category',
        ]],
        'PATCH /admin/document-categories/{category}' => ['method' => 'patchJson', 'uri' => "/api/v1/admin/document-categories/{$categoryId}", 'payload' => [
            'code' => 'MTX_CATEGORY_RENAMED',
            'name' => 'Matrix Category Renamed',
        ]],
        'DELETE /admin/document-categories/{category}' => ['method' => 'deleteJson', 'uri' => "/api/v1/admin/document-categories/{$categoryId}", 'payload' => []],
        'GET /admin/documents' => ['method' => 'getJson', 'uri' => '/api/v1/admin/documents', 'payload' => []],
        'POST /admin/documents' => ['method' => 'postJson', 'uri' => '/api/v1/admin/documents', 'payload' => [
            'code' => 'MTX_NEW_DOCUMENT',
            'name' => 'Matrix New Document',
            'category_id' => $documentCategoryId,
        ]],
        'PATCH /admin/documents/{document}' => ['method' => 'patchJson', 'uri' => "/api/v1/admin/documents/{$documentId}", 'payload' => [
            'code' => 'MTX_DOCUMENT_RENAMED',
            'name' => 'Matrix Document Renamed',
            'category_id' => $documentCategoryId,
        ]],
        'DELETE /admin/documents/{document}' => ['method' => 'deleteJson', 'uri' => "/api/v1/admin/documents/{$documentId}", 'payload' => []],
    ];
}

it('enforces the documented matrix across every document-catalog route', function (): void {
    $expected = [
        'hr-admin' => [
            'GET /documents/catalog' => true,
            'GET /admin/document-categories' => true,
            'POST /admin/document-categories' => true,
            'PATCH /admin/document-categories/{category}' => true,
            'DELETE /admin/document-categories/{category}' => true,
            'GET /admin/documents' => true,
            'POST /admin/documents' => true,
            'PATCH /admin/documents/{document}' => true,
            'DELETE /admin/documents/{document}' => true,
        ],
        'self-only' => [
            'GET /documents/catalog' => true,
            'GET /admin/document-categories' => false,
            'POST /admin/document-categories' => false,
            'PATCH /admin/document-categories/{category}' => false,
            'DELETE /admin/document-categories/{category}' => false,
            'GET /admin/documents' => false,
            'POST /admin/documents' => false,
            'PATCH /admin/documents/{document}' => false,
            'DELETE /admin/documents/{document}' => false,
        ],
        'stranger' => [
            'GET /documents/catalog' => true,
            'GET /admin/document-categories' => false,
            'POST /admin/document-categories' => false,
            'PATCH /admin/document-categories/{category}' => false,
            'DELETE /admin/document-categories/{category}' => false,
            'GET /admin/documents' => false,
            'POST /admin/documents' => false,
            'PATCH /admin/documents/{document}' => false,
            'DELETE /admin/documents/{document}' => false,
        ],
        'system-admin' => [
            'GET /documents/catalog' => true,
            'GET /admin/document-categories' => true,
            'POST /admin/document-categories' => true,
            'PATCH /admin/document-categories/{category}' => true,
            'DELETE /admin/document-categories/{category}' => true,
            'GET /admin/documents' => true,
            'POST /admin/documents' => true,
            'PATCH /admin/documents/{document}' => true,
            'DELETE /admin/documents/{document}' => true,
        ],
    ];

    $failures = [];

    foreach ($expected as $actorName => $routeExpectations) {
        foreach ($routeExpectations as $routeName => $allowed) {
            // Wipe and recreate ALL of the fixture before every cell, not just the row a
            // DELETE cell destroys — cheap, and it means the state going into every one of
            // the 36 cells is identical regardless of what the previous cell did to it.
            // Documents before categories: a document's category_id is a real FK.
            //
            // Two categories, not one: $category is the PATCH/DELETE target for the
            // /admin/document-categories routes and is deliberately left with no documents
            // under it, while $document hangs off a SEPARATE $documentCategory. If the
            // document's own category were the one under test in the DELETE-category cell,
            // an authorized actor's delete would 409 (document_catalog_in_use) instead of
            // succeeding — see DocumentCategoryCrudTest's "refuses to delete a category
            // that still has documents" — turning a genuinely "allowed" cell into a false
            // failure that has nothing to do with authorization.
            //
            // This is a manual delete-and-recreate, not $this->refreshApplication():
            // ProfileScopeMatrixTest documents why that deadlocks under RefreshDatabase — a
            // freshly booted app gets a brand-new Postgres connection that cannot see the
            // fixtures created inside the still-open outer transaction the ORIGINAL
            // connection is holding.
            Document::query()->delete();
            DocumentCategory::query()->delete();

            $category = DocumentCategory::factory()->create();
            $documentCategory = DocumentCategory::factory()->create();
            $document = Document::factory()->create(['category_id' => $documentCategory->id]);

            $routes = documentCatalogMatrixRoutes($category->id, $documentCategory->id, $document->id);
            $route = $routes[$routeName];
            $method = $route['method'];

            $response = $this->actingAs($this->actors[$actorName])
                ->{$method}($route['uri'], $route['payload']);

            $status = $response->getStatusCode();

            // The denied branch asserts FORBIDDEN SPECIFICALLY, not merely "not 2xx" — a
            // bare `>= 200 && < 300` check cannot tell a 403 apart from a 404, and a 404
            // here would mean someone applied the profile module's 404-not-403 enumeration
            // guard (docs/05-rbac.md) to a route with no owner id in the URL for it to
            // protect. `$allowed` still governs which check applies, so the diagnosability
            // of the failure message (actor + route + status) is unchanged either way.
            $succeeded = $status >= 200 && $status < 300;
            $ok = $allowed ? $succeeded : $status === 403;

            if (! $ok) {
                $failures[] = sprintf(
                    '%s -> %s: expected %s, got HTTP %d',
                    $actorName,
                    $routeName,
                    $allowed ? 'allowed' : 'denied (403)',
                    $status,
                );
            }
        }
    }

    expect($failures)->toBe([]);
});
