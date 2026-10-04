<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Models\UserNotification;
use Illuminate\Http\Request;

/** CL-14 / PRO-10: notification centre and email preferences. */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $items = UserNotification::where('user_id', $request->user()->id)
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->latest()->paginate(30);

        return response()->json([
            'data' => $items->getCollection()->map(fn (UserNotification $n) => [
                'id' => $n->id, 'title' => $n->title, 'body' => $n->body, 'link' => $n->link,
                'read_at' => $n->read_at?->toIso8601String(), 'created_at' => $n->created_at->toIso8601String(),
            ]),
            'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total(), 'per_page' => $items->perPage()],
            'unread' => UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count(),
        ]);
    }

    public function unreadCount(Request $request)
    {
        return response()->json(['unread' => UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count()]);
    }

    public function markRead(Request $request, UserNotification $notification)
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request)
    {
        UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function preferences(Request $request)
    {
        $pref = NotificationPreference::firstOrCreate(['user_id' => $request->user()->id]);

        return response()->json(['data' => $pref->only(['session_reminders', 'marketing_emails', 'product_news'])]);
    }

    public function updatePreferences(Request $request)
    {
        $data = $request->validate([
            'session_reminders' => ['sometimes', 'boolean'],
            'marketing_emails' => ['sometimes', 'boolean'],
            'product_news' => ['sometimes', 'boolean'],
        ]);
        $pref = NotificationPreference::firstOrCreate(['user_id' => $request->user()->id]);
        $pref->update($data);

        return response()->json(['data' => $pref->only(['session_reminders', 'marketing_emails', 'product_news'])]);
    }
}
