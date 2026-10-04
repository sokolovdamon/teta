<?php

namespace App\Modules\Notifications\Events;

use App\Modules\Notifications\Models\UserNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class UserNotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public UserNotification $notification) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->notification->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'title' => $this->notification->title,
            'body' => $this->notification->body,
            'link' => $this->notification->link,
            'created_at' => $this->notification->created_at?->toIso8601String(),
        ];
    }
}
