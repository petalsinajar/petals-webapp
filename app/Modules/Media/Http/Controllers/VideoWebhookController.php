<?php

namespace App\Modules\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Media\Contracts\VideoProvider;
use App\Modules\Media\Services\MediaProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /v1/webhooks/video — the video provider says a video is ready (or failed). */
class VideoWebhookController extends Controller
{
    public function __invoke(Request $request, VideoProvider $provider, MediaProcessor $processor): JsonResponse
    {
        abort_unless($provider->verifyWebhook($request), 401, 'Bad signature.');

        $status = $provider->parseWebhook($request->json()->all());
        if ($status->ref !== '') {
            $processor->videoUpdated($status);
        }

        return response()->json(['ok' => true]);
    }
}
