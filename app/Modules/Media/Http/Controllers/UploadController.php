<?php

namespace App\Modules\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * POST /v1/uploads — multipart `file` (an image, up to 10 MB).
 * Returns `{ url, width, height }`; send the url wherever a `*_url` field is
 * expected (event covers, avatars, post media).
 *
 * Stored on the `eventapp.uploads.disk` disk (public by default, which needs
 * `php artisan storage:link` and APP_URL set to the public host).
 */
class UploadController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:10240'],
        ]);

        $file = $request->file('file');
        $disk = config('eventapp.uploads.disk');
        $path = $file->store('uploads/'.now()->format('Y/m'), $disk);
        [$width, $height] = getimagesize($file->getRealPath()) ?: [null, null];

        return response()->json([
            'url' => Storage::disk($disk)->url($path),
            'width' => $width,
            'height' => $height,
        ], 201);
    }
}
