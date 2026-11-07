<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** GET /v1/notifications?vendor_id= — personal inbox, or one vendor's team inbox. */
    public function index(Request $request): JsonResponse
    {
        $vendorId = $request->query('vendor_id');

        $page = $request->user()->notifications()
            ->when($vendorId, fn ($q) => $q->where('data->vendor_id', $vendorId))
            ->reorder()->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn ($n) => [
                'id' => $n->id,
                ...$n->data,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at->toIso8601String(),
            ]),
            'unread' => $request->user()->unreadNotifications()->count(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /** POST /v1/notifications/read — body: { ids?: [...] } (omit to mark all read) */
    public function markRead(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['sometimes', 'array', 'max:200'], 'ids.*' => ['uuid']]);

        $request->user()->unreadNotifications()
            ->when($data['ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->update(['read_at' => now()]);

        return response()->json(['unread' => $request->user()->unreadNotifications()->count()]);
    }
}
