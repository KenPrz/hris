<?php

declare(strict_types=1);

namespace App\Actions\Compute;

use App\Domain\Compute\RecomputeTrigger;
use App\Jobs\RecomputeDay;
use App\Models\RecomputeRun;
use Illuminate\Support\Facades\Bus;

/**
 * Dispatches a deduped batch of RecomputeDay jobs for an arbitrary set of
 * (employee, date) pairs, and audits the run as a recompute_runs row.
 *
 * Lives under App\Actions rather than App\Domain: it calls Bus::batch() and writes a
 * RecomputeRun, and the Arch suite's "the domain layer is framework-agnostic" rule
 * (tests/Arch/ConventionsTest.php) bars Illuminate\Support\Facades and Illuminate\Database
 * from App\Domain outright — this is an orchestration/dispatch concern (fan out a write +
 * a queue dispatch), the same shape as ComputeDailySummary, not a pure calculation like
 * DailyComputation. RecomputeTrigger (the enum this class takes) stays in App\Domain\Compute
 * since it is a plain, framework-agnostic value object; only the dispatching service moves.
 *
 * Multiple resolvers (a holiday's office+date, a pay rule's effective-date range, a shift
 * template's assigned employees) can each name the same (employee, date) pair — a holiday
 * added the same day a pay rule changes, say. Deduping here rather than in each resolver
 * means a pair is recomputed exactly once regardless of how many reasons it had.
 */
final class RecomputeRange
{
    public static function dispatch(
        iterable $pairs,
        RecomputeTrigger $trigger,
        ?string $triggerId,
        string $reason,
        ?string $causedBy = null,
    ): ?RecomputeRun {
        $deduped = collect($pairs)
            ->unique(fn (array $pair): string => $pair['employee_id'].'|'.$pair['date'])
            ->values();

        if ($deduped->isEmpty()) {
            return null;
        }

        $run = RecomputeRun::create([
            'trigger_type' => $trigger,
            'trigger_id' => $triggerId,
            'reason' => $reason,
            'pair_count' => $deduped->count(),
            'status' => 'queued',
            'caused_by' => $causedBy,
        ]);

        $jobs = $deduped
            ->map(fn (array $pair): RecomputeDay => new RecomputeDay($pair['employee_id'], $pair['date']))
            ->all();

        $batch = Bus::batch($jobs)
            ->name("recompute:{$trigger->value}")
            ->then(function () use ($run): void {
                $run->update(['status' => 'completed']);
            })
            ->catch(function () use ($run): void {
                $run->update(['status' => 'failed']);
            })
            ->dispatch();

        $run->batch_id = $batch->id;
        $run->save();

        return $run;
    }
}
