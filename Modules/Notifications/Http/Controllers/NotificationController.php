<?php

namespace Modules\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Notifications\Http\Requests\ListNotificationsRequest;
use Modules\Notifications\Http\Resources\NotificationResource;
use Modules\Notifications\Models\UserNotification;
use Modules\Notifications\Services\NotificationService;
use Modules\Shared\Helpers\ApiResponse;

class NotificationController
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * List my notifications
     *
     * Newest first. Pass unread=1 to return only the unread ones.
     */
    public function index(ListNotificationsRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            $this->notifications->paginateFor(
                (int) auth('api')->id(),
                $request->boolean('unread'),
                (int) $request->validated('per_page', 15),
            ),
            NotificationResource::class,
        );
    }

    /**
     * Mark one notification as read
     *
     * Only the owner may do this; anyone else gets 403.
     */
    public function markAsRead(UserNotification $notification): JsonResponse
    {
        Gate::forUser(auth('api')->user())->authorize('update', $notification);

        return ApiResponse::ok(new NotificationResource($this->notifications->markAsRead($notification)));
    }

    /**
     * Mark all my notifications as read
     */
    public function markAllAsRead(): Response
    {
        $this->notifications->markAllAsRead((int) auth('api')->id());

        return ApiResponse::noContent();
    }
}
