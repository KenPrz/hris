<?php

declare(strict_types=1);

namespace App\Domain\Requests;

use App\Models\Request;
use App\Models\User;

/**
 * Who may act on a request's CURRENT hop. State- and type-aware: a single-hop request
 * (e.g. attendance adjustment) is decided by its requester's manager OR HR office in one
 * step; a two-hop request (e.g. leave) is decided by the manager at `pending` (hop 1) and
 * by HR at `manager_approved` (hop 2) — never the same step twice, and never the hop-1
 * approver again at hop 2. No self-approval at any hop, however broad the scope. Any
 * terminal state (approved/rejected/cancelled) has nothing left to decide.
 *
 * This deliberately does NOT reproduce EmployeeScope::visibleTo()'s system-admin-sees-all
 * branch: M6a already decided a system admin gets no approval queue (ApprovalQueues has no
 * admin view), so a bare admin account — no employee, no reports, no HR offices — was
 * already excluded from ever being handed a request to decide; this class just makes that
 * exclusion explicit instead of accidental.
 *
 * A Domain query service that touches Eloquent (via hrAdminOffices()), the same carve-out
 * already given to EmployeeScope by the framework-agnostic arch rule.
 */
final class RequestAuthority
{
    public static function isManagerOf(User $approver, Request $request): bool
    {
        $managerEmployeeId = $approver->employee?->id;

        return $managerEmployeeId !== null
            && $request->employee->current_reports_to_id === $managerEmployeeId;
    }

    public static function isHrOf(User $approver, Request $request): bool
    {
        $officeId = $request->employee->current_office_id;

        return $officeId !== null && $approver->hrAdminOffices()->whereKey($officeId)->exists();
    }

    public static function canDecide(User $approver, Request $request): bool
    {
        // Never self, regardless of hop or scope.
        if ($approver->employee?->id === $request->employee_id) {
            return false;
        }

        // A system admin retains full decision authority at the API — M6a's decision was
        // that they get no approval QUEUE (ApprovalQueues has no admin view), not that
        // they lose the ability to act. Any hop, any state: for a terminal request this
        // still yields 409 (decided) rather than 404 (out of scope), preserving the
        // authority-then-pending ordering below.
        if ($approver->is_system_admin) {
            return true;
        }

        $twoHop = $request->type->requiresHrStep();

        return match ($request->state) {
            RequestState::Pending => $twoHop
                ? self::isManagerOf($approver, $request) // hop 1: manager only
                : (self::isManagerOf($approver, $request) || self::isHrOf($approver, $request)),
            RequestState::ManagerApproved => self::isHrOf($approver, $request) // hop 2: HR only,
                && $approver->id !== $request->manager_decided_by, // and not the hop-1 approver
            // Terminal (Approved/Rejected/Cancelled): preserve the M6a existence-leak
            // discipline. An approver who HAD authority over some hop still passes here,
            // so the action's separate isTerminal() check is what yields 409 (exists, but
            // already decided) instead of collapsing that case into 404 (never had
            // authority at all) for a previously-authorized actor.
            default => self::isManagerOf($approver, $request) || self::isHrOf($approver, $request),
        };
    }
}
