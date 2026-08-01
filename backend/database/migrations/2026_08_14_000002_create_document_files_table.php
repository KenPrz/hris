<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One filed document. The FILE itself lives in spatie's `media` table on the RustFS-backed
 * `attachments` disk — this row carries only what medialibrary does not: which kind it is,
 * what it is attached to, who filed it, its hash, and its dates.
 *
 * `documentable_type` stores the FULL CLASS NAME (`App\Models\Employee`), exactly as
 * spatie's `media.model_type` and Activitylog's `activity_log.subject_type` already do —
 * there is deliberately NO Relation::morphMap() for this relation. Employee and Office both
 * already use LogsActivity, whose morph is a GLOBAL registry; aliasing either model here
 * would silently change what subject_type new activity_log rows are written under, breaking
 * the audit viewer's wire shape and its subject_type filter for existing rows. The morph
 * alias ('employee') is a wire-layer concern only — see config/documents.php and the later
 * DocumentFileResource that translates the FQCN to the config alias for API responses.
 *
 * There is deliberately NO unique constraint on (document_id, documentable_type,
 * documentable_id). An employee holds several files of the same kind over time — last year's
 * contract and this year's. Contrast M10a's unique(employee_id, category_id), which exists
 * because one employee has exactly one TIN. See the M10b spec, decision 8.
 *
 * sha256 is integrity, not deduplication: no unique index. The same PDF legitimately attaches
 * to two employees (a shared policy, a counter-signed contract).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_files', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('document_id')->constrained('documents');

            $table->text('documentable_type');
            $table->uuid('documentable_id');

            // Null on delete, not cascade: losing the user must never lose the document.
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('sha256');
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();

            $table->timestamps();

            $table->index(['documentable_type', 'documentable_id']);
            $table->index('document_id');
        });

        // Partial index: only rows that can expire are worth scanning for the
        // expiring-soon compliance read (M10b-b).
        DB::statement('create index document_files_expires_on on document_files (expires_on) where expires_on is not null');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_files');
    }
};
