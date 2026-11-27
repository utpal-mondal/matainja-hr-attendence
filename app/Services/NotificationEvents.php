<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\AssetAssignment;
use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\Document;
use App\Models\ExpenseClaim;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\Shift;
use App\Models\StaffShiftAssignment;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\WfhRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Which change notifies whom. Hooked to model events so the API and the
 * /admin web panel send the same notifications.
 *
 * `data.type` (and the id keys) tell the app which screen to open.
 */
class NotificationEvents
{
    public static function register(): void
    {
        $n = fn (): NotificationService => app(NotificationService::class);

        // ------------------------------------------------------------ leave
        LeaveRequest::created(function (LeaveRequest $leave) use ($n) {
            $leave->loadMissing('staff', 'leaveType');
            $what = self::leaveText($leave);
            if ($leave->status === 'pending') {
                $n()->notify($n()->approversFor($leave->staff), 'leave_request', 'New leave request',
                    "{$leave->staff?->full_name} requested {$what}.", ['leave_request_id' => $leave->id]);
                $n()->confirm($leave->staff, 'leave_submitted', 'Leave request submitted',
                    ucfirst($what).' is pending approval.', ['leave_request_id' => $leave->id]);
            } elseif ($leave->status === 'approved') {
                $n()->notify($n()->userOf($leave->staff), 'leave_approved', 'Leave added',
                    ucfirst($what).' was added for you.', ['leave_request_id' => $leave->id]);
            }
        });
        LeaveRequest::updated(function (LeaveRequest $leave) use ($n) {
            if (! $leave->wasChanged('status')) {
                return;
            }
            $leave->loadMissing('staff', 'leaveType');
            $what = self::leaveText($leave);
            $data = ['leave_request_id' => $leave->id];
            match ($leave->status) {
                'approved' => $n()->notify($n()->userOf($leave->staff), 'leave_approved', 'Leave approved', 'Your '.$what.' was approved.', $data),
                'rejected' => $n()->notify($n()->userOf($leave->staff), 'leave_rejected', 'Leave rejected', 'Your '.$what.' was rejected.', $data),
                'withdrawn' => $n()->notify($n()->approversFor($leave->staff), 'leave_withdrawn', 'Leave request withdrawn', "{$leave->staff?->full_name} withdrew {$what}.", $data),
                'cancelled' => $n()->notify($n()->approversFor($leave->staff)->push($leave->staff?->user_id), 'leave_cancelled', 'Leave cancelled', "{$leave->staff?->full_name}'s {$what} was cancelled.", $data),
                default => null,
            };
        });

        // -------------------------------------------------------------- WFH
        WfhRequest::created(function (WfhRequest $wfh) use ($n) {
            $wfh->loadMissing('staff');
            $when = self::range($wfh->from_date, $wfh->to_date);
            if (($wfh->status ?? 'pending') === 'pending') {
                $n()->notify($n()->approversFor($wfh->staff), 'wfh_request', 'New WFH request',
                    "{$wfh->staff?->full_name} requested work from home for {$when}.", ['wfh_request_id' => $wfh->id]);
                $n()->confirm($wfh->staff, 'wfh_submitted', 'WFH request submitted',
                    "Your work from home request for {$when} is pending approval.", ['wfh_request_id' => $wfh->id]);
            } elseif ($wfh->status === 'approved') {
                $n()->notify($n()->userOf($wfh->staff), 'wfh_approved', 'Work from home added',
                    "Work from home was added for you for {$when}.", ['wfh_request_id' => $wfh->id]);
            }
        });
        WfhRequest::updated(function (WfhRequest $wfh) use ($n) {
            if (! $wfh->wasChanged('status')) {
                return;
            }
            $wfh->loadMissing('staff');
            $when = self::range($wfh->from_date, $wfh->to_date);
            $data = ['wfh_request_id' => $wfh->id];
            match ($wfh->status) {
                'approved' => $n()->notify($n()->userOf($wfh->staff), 'wfh_approved', 'WFH approved', "Your work from home for {$when} was approved.", $data),
                'rejected' => $n()->notify($n()->userOf($wfh->staff), 'wfh_rejected', 'WFH rejected', "Your work from home for {$when} was rejected.", $data),
                'cancelled', 'withdrawn' => $n()->notify($n()->approversFor($wfh->staff)->push($wfh->staff?->user_id), 'wfh_cancelled', 'WFH cancelled', "{$wfh->staff?->full_name}'s work from home for {$when} was cancelled.", $data),
                default => null,
            };
        });

        // ----------------------------------------- attendance corrections
        AttendanceCorrectionRequest::created(function (AttendanceCorrectionRequest $c) use ($n) {
            $c->loadMissing('staff');
            $n()->notify($n()->approversFor($c->staff), 'correction_request', 'Attendance correction request',
                "{$c->staff?->full_name} asked to correct attendance for ".self::day($c->work_date).'.', ['correction_id' => $c->id]);
            $n()->confirm($c->staff, 'correction_submitted', 'Regularization submitted',
                'Your attendance correction for '.self::day($c->work_date).' is waiting for approval.', ['correction_id' => $c->id]);
        });

        // -------------------------------------------- own check-in / out
        // In `saved` the attributes are still dirty (original not synced yet),
        // which covers both a new row and an update.
        Attendance::saved(function (Attendance $a) use ($n) {
            if ($a->isDirty('check_in_at') && $a->check_in_at && $a->getOriginal('check_in_at') === null) {
                $n()->confirm($a->staff_id, 'attendance', 'Checked in', 'You checked in at '.self::clock($a->check_in_at).'.', ['attendance_id' => $a->id]);
            }
            if ($a->isDirty('check_out_at') && $a->check_out_at && $a->getOriginal('check_out_at') === null) {
                $worked = (int) $a->worked_minutes;
                $n()->confirm($a->staff_id, 'attendance', 'Checked out',
                    'Day completed - '.intdiv($worked, 60).'h '.($worked % 60).'m worked.', ['attendance_id' => $a->id]);
            }
        });
        AttendanceCorrectionRequest::updated(function (AttendanceCorrectionRequest $c) use ($n) {
            if (! $c->wasChanged('status') || ! in_array($c->status, ['approved', 'rejected'], true)) {
                return;
            }
            $c->loadMissing('staff');
            $n()->notify($n()->userOf($c->staff), "correction_{$c->status}", 'Attendance correction '.$c->status,
                'Your attendance correction for '.self::day($c->work_date)." was {$c->status}.", ['correction_id' => $c->id]);
        });

        // --------------------------------------------------------- holidays
        Holiday::created(fn (Holiday $h) => $n()->notify($n()->everyone($h->branch_id), 'holiday', 'New holiday',
            "{$h->name} on ".self::day($h->holiday_date).' is a holiday.', ['holiday_id' => $h->id]));
        Holiday::updated(function (Holiday $h) use ($n) {
            if ($h->wasChanged(['name', 'holiday_date', 'type', 'branch_id'])) {
                $n()->notify($n()->everyone($h->branch_id), 'holiday', 'Holiday updated',
                    "{$h->name} is now on ".self::day($h->holiday_date).'.', ['holiday_id' => $h->id]);
            }
        });
        Holiday::deleted(fn (Holiday $h) => $n()->notify($n()->everyone($h->branch_id), 'holiday', 'Holiday removed',
            "{$h->name} on ".self::day($h->holiday_date).' is no longer a holiday.'));

        // ---------------------------------------------------- announcements
        Announcement::created(function (Announcement $a) use ($n) {
            if ($a->published_at) {
                self::announce($n(), $a);
            }
        });
        Announcement::updated(function (Announcement $a) use ($n) {
            if ($a->wasChanged('published_at') && $a->published_at && $a->getOriginal('published_at') === null) {
                self::announce($n(), $a);
            }
        });

        // ----------------------------------------------------------- shifts
        Shift::updated(function (Shift $shift) use ($n) {
            if (! $shift->wasChanged(['name', 'start_time', 'end_time', 'grace_minutes', 'crosses_midnight'])) {
                return;
            }
            $today = now()->toDateString();
            $userIds = $shift->assignments()
                ->where('effective_from', '<=', $today)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
                ->with('staff:id,user_id')
                ->get()
                ->pluck('staff.user_id');
            $n()->notify($userIds, 'shift', 'Shift timing changed',
                "{$shift->name} is now ".self::shiftTime($shift).'.', ['shift_id' => $shift->id]);
        });
        StaffShiftAssignment::created(function (StaffShiftAssignment $a) use ($n) {
            $a->loadMissing('shift', 'staff');
            $n()->notify($n()->userOf($a->staff), 'shift', 'Shift assigned',
                "You are on {$a->shift?->name} (".($a->shift ? self::shiftTime($a->shift) : '').') from '.self::day($a->effective_from).'.',
                ['shift_id' => $a->shift_id]);
        });

        // ----------------------------------------------------------- assets
        AssetAssignment::created(function (AssetAssignment $a) use ($n) {
            $a->loadMissing('asset', 'staff');
            $n()->notify($n()->userOf($a->staff), 'asset_assigned', 'Asset assigned',
                "{$a->asset?->name} ({$a->asset?->asset_tag}) was assigned to you.", ['asset_id' => $a->asset_id]);
        });
        AssetAssignment::updated(function (AssetAssignment $a) use ($n) {
            if ($a->wasChanged('returned_at') && $a->returned_at && $a->getOriginal('returned_at') === null) {
                $a->loadMissing('asset', 'staff');
                $n()->notify($n()->userOf($a->staff), 'asset_returned', 'Asset returned',
                    "{$a->asset?->name} ({$a->asset?->asset_tag}) was marked as returned.", ['asset_id' => $a->asset_id]);
            }
        });

        // --------------------------------------------------------- expenses
        ExpenseClaim::created(function (ExpenseClaim $e) use ($n) {
            if ($e->status === 'submitted') {
                $e->loadMissing('staff');
                $amount = trim(($e->currency ?? '').' '.number_format((float) $e->amount, 2));
                $n()->notify($n()->approversFor($e->staff), 'expense_submitted', 'New expense claim',
                    "{$e->staff?->full_name} submitted a claim of {$amount}.", ['expense_id' => $e->id]);
                $n()->confirm($e->staff, 'expense_pending', 'Expense claim submitted', "Your claim of {$amount} is pending approval.", ['expense_id' => $e->id]);
            }
        });
        ExpenseClaim::updated(function (ExpenseClaim $e) use ($n) {
            if (! $e->wasChanged('status')) {
                return;
            }
            $e->loadMissing('staff');
            $amount = trim(($e->currency ?? '').' '.number_format((float) $e->amount, 2));
            $data = ['expense_id' => $e->id];
            match ($e->status) {
                'submitted' => [
                    $n()->notify($n()->approversFor($e->staff), 'expense_submitted', 'New expense claim', "{$e->staff?->full_name} submitted a claim of {$amount}.", $data),
                    $n()->confirm($e->staff, 'expense_pending', 'Expense claim submitted', "Your claim of {$amount} is pending approval.", $data),
                ],
                'approved' => $n()->notify($n()->userOf($e->staff), 'expense_approved', 'Expense approved', "Your claim of {$amount} was approved.", $data),
                'rejected' => $n()->notify($n()->userOf($e->staff), 'expense_rejected', 'Expense rejected', "Your claim of {$amount} was rejected.", $data),
                'paid' => $n()->notify($n()->userOf($e->staff), 'expense_paid', 'Expense paid', "Your claim of {$amount} was paid.", $data),
                default => null,
            };
        });

        // --------------------------------------------------------- meetings
        MeetingParticipant::created(function (MeetingParticipant $p) use ($n) {
            $p->loadMissing('meeting', 'staff');
            $n()->notify($n()->userOf($p->staff), 'meeting', 'Meeting invitation',
                "{$p->meeting?->title} on ".self::when($p->meeting?->starts_at).'.', ['meeting_id' => $p->meeting_id]);
        });
        Meeting::updated(function (Meeting $m) use ($n) {
            $cancelled = $m->wasChanged('status') && $m->status === 'cancelled';
            if (! $cancelled && ! $m->wasChanged(['starts_at', 'ends_at', 'room_id', 'meeting_url'])) {
                return;
            }
            $userIds = $m->participants()->with('staff:id,user_id')->get()->pluck('staff.user_id');
            $cancelled
                ? $n()->notify($userIds, 'meeting', 'Meeting cancelled', "{$m->title} on ".self::when($m->starts_at).' was cancelled.', ['meeting_id' => $m->id])
                : $n()->notify($userIds, 'meeting', 'Meeting updated', "{$m->title} is now on ".self::when($m->starts_at).'.', ['meeting_id' => $m->id]);
        });

        // --------------------------------------------------------- helpdesk
        Ticket::created(function (Ticket $t) use ($n) {
            $t->loadMissing('staff');
            $n()->notify($n()->admins(), 'ticket', 'New helpdesk ticket',
                "{$t->staff?->full_name}: {$t->subject}", ['ticket_id' => $t->id]);
        });
        Ticket::updated(function (Ticket $t) use ($n) {
            $t->loadMissing('staff');
            if ($t->wasChanged('status')) {
                $n()->notify($n()->userOf($t->staff), 'ticket', 'Ticket '.Str::of($t->status)->replace('_', ' ')->lower(),
                    "{$t->ticket_number}: {$t->subject}", ['ticket_id' => $t->id]);
            }
            if ($t->wasChanged('assigned_to') && $t->assigned_to) {
                $n()->notify($t->assigned_to, 'ticket', 'Ticket assigned to you', "{$t->ticket_number}: {$t->subject}", ['ticket_id' => $t->id]);
            }
        });
        TicketComment::created(function (TicketComment $c) use ($n) {
            $c->loadMissing('ticket.staff');
            $t = $c->ticket;
            $n()->notify([$t?->staff?->user_id, $t?->assigned_to], 'ticket', "New reply on {$t?->ticket_number}",
                Str::limit((string) $c->comment, 120), ['ticket_id' => $c->ticket_id]);
        });

        // -------------------------------------------------------- documents
        Document::created(function (Document $d) use ($n) {
            if ($d->owner_staff_id) {
                $n()->notify($n()->userOf($d->owner_staff_id), 'document', 'New document', "{$d->title} was added to your documents.", ['document_id' => $d->id]);
            }
        });
    }

    private static function announce(NotificationService $n, Announcement $a): void
    {
        $n->notify($n->everyone(), 'announcement', $a->title, Str::limit(strip_tags((string) $a->body), 180), ['announcement_id' => $a->id]);
    }

    private static function leaveText(LeaveRequest $leave): string
    {
        $type = $leave->leaveType?->name ? Str::lower($leave->leaveType->name) : 'leave';
        $days = (float) $leave->total_days;
        $daysText = $days == 1 ? '1 day' : rtrim(rtrim(number_format($days, 1), '0'), '.').' days';

        return trim(Str::endsWith($type, 'leave') ? $type : "{$type} leave")
            .' for '.self::range($leave->from_date, $leave->to_date)." ({$daysText})";
    }

    private static function clock(?CarbonInterface $at): string
    {
        return $at?->copy()->timezone(config('app.timezone'))->format('h:i A') ?? '';
    }

    private static function day(?CarbonInterface $date): string
    {
        return $date?->format('D, d M Y') ?? '';
    }

    private static function when(?CarbonInterface $at): string
    {
        return $at?->copy()->timezone(config('app.timezone'))->format('d M, h:i A') ?? '';
    }

    private static function range(?CarbonInterface $from, ?CarbonInterface $to): string
    {
        if (! $from) {
            return '';
        }

        return ! $to || $from->isSameDay($to) ? $from->format('d M Y') : $from->format('d M').' - '.$to->format('d M Y');
    }

    private static function shiftTime(Shift $shift): string
    {
        $fmt = fn ($t) => $t ? date('h:i A', strtotime((string) $t)) : '';

        return $fmt($shift->start_time).' - '.$fmt($shift->end_time);
    }
}
