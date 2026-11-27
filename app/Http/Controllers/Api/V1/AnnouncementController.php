<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\AuditLog;
use App\Support\ApiResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $announcements = Announcement::with(['author', 'reads'])
            ->whereNotNull('published_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('published_at')
            ->paginate(20);

        return ApiResponder::success(
            AnnouncementResource::collection($announcements)->resolve(),
            'Announcements retrieved'
        );
    }

    public function show(Announcement $announcement): JsonResponse
    {
        return ApiResponder::success(
            new AnnouncementResource($announcement->load(['author', 'reads'])),
            'Announcement retrieved'
        );
    }

    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $announcement = DB::transaction(function () use ($validated, $request) {
            $announcement = Announcement::create([
                'title' => $validated['title'],
                'body' => $validated['body'],
                'audience' => $validated['audience'] ?? 'all',
                'audience_meta' => $validated['audience_meta'] ?? null,
                'expires_at' => $validated['expires_at'] ?? null,
                'published_at' => $request->boolean('publish') ? now() : null,
                'created_by' => $request->user()->id,
            ]);

            return $announcement;
        });

        AuditLog::record('announcement.created', $announcement);

        return ApiResponder::success(
            new AnnouncementResource($announcement->load(['author', 'reads'])),
            'Announcement created',
            201
        );
    }

    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        if ($announcement->created_by !== $request->user()->id && ! $request->user()->isAdmin()) {
            return ApiResponder::error('You do not have permission to perform this action.', 403);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string', 'max:10000'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $announcement->update($validated);

        return ApiResponder::success(
            new AnnouncementResource($announcement->fresh()->load(['author', 'reads'])),
            'Announcement updated'
        );
    }

    public function publish(Announcement $announcement): JsonResponse
    {
        if ($announcement->published_at) {
            return ApiResponder::error('Already published.', 409);
        }

        DB::transaction(function () use ($announcement) {
            $announcement->update(['published_at' => now()]); // notifies everyone (NotificationEvents)
        });

        AuditLog::record('announcement.published', $announcement);

        return ApiResponder::success(
            new AnnouncementResource($announcement->fresh()),
            'Announcement published'
        );
    }

    public function markRead(Request $request, Announcement $announcement): JsonResponse
    {
        $staffId = $request->user()->staff?->id;

        if (! $staffId) {
            return ApiResponder::error('No staff profile linked to this account.', 404);
        }

        AnnouncementRead::firstOrCreate(
            ['announcement_id' => $announcement->id, 'staff_id' => $staffId],
            ['read_at' => now()]
        );

        return ApiResponder::success(null, 'Marked as read');
    }
}
