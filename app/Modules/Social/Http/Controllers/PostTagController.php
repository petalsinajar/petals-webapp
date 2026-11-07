<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Social\Models\PostTag;
use App\Modules\Social\Services\PostPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Vendors approve when other people tag their items in posts. */
class PostTagController extends Controller
{
    /** GET /v1/vendors/{vendor}/post-tags?status=pending */
    public function index(Request $request, Vendor $vendor, PostPresenter $presenter): JsonResponse
    {
        Gate::authorize('manage', $vendor);

        $page = PostTag::where('vendor_id', $vendor->id)
            ->where('status', $request->query('status', 'pending'))
            ->whereHas('post', fn ($q) => $q->published())
            ->with('post')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (PostTag $tag) => [
                'id' => $tag->id,
                'status' => $tag->status,
                'target' => ['type' => $tag->target_type, 'id' => $tag->target_id, 'variant_id' => $tag->variant_id],
                'post' => $presenter->one($tag->post, $request->user()),
            ]),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /** POST /v1/vendors/{vendor}/post-tags/{tag} — body: { status: approved|rejected } */
    public function update(Request $request, Vendor $vendor, PostTag $tag): JsonResponse
    {
        Gate::authorize('manage', $vendor);
        abort_unless($tag->vendor_id === $vendor->id, 404);

        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'rejected'])]]);
        $tag->update(['status' => $data['status']]);

        return response()->json(['id' => $tag->id, 'status' => $tag->status]);
    }
}
