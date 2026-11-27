<?php

namespace App\Services;

use App\Models\InAppNotification;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Creates notifications (GET /notifications). The mobile app checks that
 * list in the background and shows new rows as phone notifications, so no
 * third-party push service is involved. The person who caused the change is
 * never told about it. Model events wiring lives in NotificationEvents.
 */
class NotificationService
{
    private static int $muted = 0;

    /** Run [$callback] without sending notifications (bulk imports, seeders). */
    public static function muted(callable $callback): mixed
    {
        self::$muted++;
        try {
            return $callback();
        } finally {
            self::$muted--;
        }
    }

    public static function isMuted(): bool
    {
        // Seeders, tinker and artisan imports should not message everyone.
        return self::$muted > 0 || (app()->runningInConsole() && ! app()->runningUnitTests());
    }

    /**
     * @param  iterable<int|User|null>|int|User|null  $users
     * @param  array<string, scalar|null>  $data
     */
    public function notify(iterable|int|User|null $users, string $type, string $title, string $body, array $data = []): void
    {
        if (self::isMuted()) {
            return;
        }

        $actorId = auth()->id();
        $userIds = collect(is_iterable($users) ? $users : [$users])
            ->map(fn ($u) => $u instanceof User ? $u->id : $u)
            ->filter()
            ->unique()
            ->reject(fn ($id) => $id === $actorId)
            ->values()
            ->all();

        if ($userIds === []) {
            return;
        }

        $data = ['type' => $type, ...$data];
        foreach ($userIds as $userId) {
            InAppNotification::create([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ]);
        }
    }

    /**
     * Keeps a record of the signed-in person's own action ("Leave request
     * submitted", "Checked in") in their notification history. Saved as read:
     * it does not raise the unread badge or pop up on the phone. Only when
     * the change was made by that person (not by an admin on their behalf).
     *
     * @param  array<string, scalar|null>  $data
     */
    public function confirm(Staff|int|null $staff, string $type, string $title, string $body, array $data = []): void
    {
        $userId = $this->userOf($staff);
        if (self::isMuted() || $userId === null || $userId !== auth()->id()) {
            return;
        }

        InAppNotification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => ['type' => $type, ...$data],
            'read_at' => now(),
        ]);
    }

    // ------------------------------------------------------------ recipients

    public function userOf(Staff|int|null $staff): ?int
    {
        if ($staff === null) {
            return null;
        }

        return $staff instanceof Staff ? $staff->user_id : Staff::whereKey($staff)->value('user_id');
    }

    /** @return Collection<int, int> HR Admin and Super Admin accounts. */
    public function admins(): Collection
    {
        return User::whereHas('roles', fn ($q) => $q->whereIn('name', ['HR Admin', 'Super Admin']))->pluck('id');
    }

    /** @return Collection<int, int> Whoever reviews this person's requests: their manager plus HR / Super Admin. */
    public function approversFor(Staff|int|null $staff): Collection
    {
        $staff = $staff instanceof Staff ? $staff : ($staff ? Staff::find($staff) : null);

        return $this->admins()->push($staff?->manager?->user_id)->filter()->unique()->values();
    }

    /** @return Collection<int, int> Every active employee plus the admins. */
    public function everyone(?int $branchId = null): Collection
    {
        $staffUsers = Staff::where('employment_status', '!=', 'terminated')
            ->whereNotNull('user_id')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->pluck('user_id');

        return $staffUsers->merge($this->admins())->unique()->values();
    }
}
