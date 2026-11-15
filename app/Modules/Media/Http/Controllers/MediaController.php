<?php

namespace App\Modules\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MediaController extends Controller
{
    /** GET /v1/media/{media} — status and URLs, for polling while it processes. */
    public function show(Request $request, Media $media, MediaProcessor $processor): JsonResponse
    {
        $user = $request->user();
        $allowed = $media->owner_id === $user->id
            || ($media->vendor_id && Gate::allows('operate', $media->vendor));
        abort_unless($allowed, 404);

        // Don't depend on the video provider's webhook alone.
        $processor->refreshVideo($media);
        $media->refresh();

        return response()->json([
            ...$media->toPayload(),
            'failure_reason' => $media->failure_reason,
            'attached' => $media->attached_at !== null,
        ]);
    }
}
