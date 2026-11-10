<?php

namespace App\Modules\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\UploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Direct uploads. The app asks for an upload URL, sends the file straight to storage (or the
 * video provider), then calls complete. Files never pass through this server.
 */
class UploadController extends Controller
{
    public function __construct(private readonly UploadService $uploads) {}

    /**
     * POST /v1/uploads { kind, mime, bytes, purpose, vendor_id?, duration_ms? }
     * → { media_id, kind, status, upload: { method, url, headers, expires_at } }
     *   (multipart: `parts` instead of `url`; video: `method: POST`, form `field`)
     */
    public function store(Request $request): JsonResponse
    {
        $config = config('eventapp.media');
        $data = $request->validate([
            'kind' => ['required', Rule::in(['image', 'video'])],
            'purpose' => ['required', Rule::in(array_keys($config['purposes']))],
            'mime' => ['required', 'string'],
            'bytes' => ['required', 'integer', 'min:1'],
            'duration_ms' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'vendor_id' => ['sometimes', 'nullable', 'ulid'],
        ]);

        $kind = $config[$data['kind']];
        $errors = [];
        if (! in_array($data['kind'], $config['purposes'][$data['purpose']], true)) {
            $errors['kind'] = "A {$data['kind']} can't be used for {$data['purpose']}.";
        }
        if (! in_array(strtolower($data['mime']), $kind['mimes'], true)) {
            $errors['mime'] = 'Use '.implode(', ', $kind['mimes']).'.';
        }
        if ($data['bytes'] > $kind['max_bytes']) {
            $errors['bytes'] = 'The file is too big (at most '.intdiv($kind['max_bytes'], 1024 * 1024).' MB).';
        }
        if ($data['kind'] === 'video' && ($data['duration_ms'] ?? 0) > $kind['max_duration_seconds'] * 1000) {
            $errors['duration_ms'] = 'Videos can be at most '.intdiv($kind['max_duration_seconds'], 60).' minutes.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $vendor = null;
        if (! empty($data['vendor_id'])) {
            $vendor = Vendor::findOrFail(strtolower($data['vendor_id']));
            Gate::authorize('operate', $vendor);
        }

        [$media, $upload] = $this->uploads->request($request->user(), $vendor, [...$data, 'mime' => strtolower($data['mime'])]);

        return response()->json([
            'media_id' => $media->id,
            'kind' => $media->kind->value,
            'status' => $media->status->value,
            'upload' => $upload->toArray(),
        ], 201);
    }

    /** POST /v1/uploads/{media}/complete { parts?: [{ part_number, etag }] } — the uploader only. */
    public function complete(Request $request, Media $media): JsonResponse
    {
        abort_unless($media->owner_id === $request->user()->id, 403, 'Only the uploader can complete an upload.');
        $data = $request->validate([
            'parts' => ['sometimes', 'array', 'max:10000'],
            'parts.*.part_number' => ['required', 'integer', 'min:1', 'max:10000'],
            'parts.*.etag' => ['required', 'string', 'max:200'],
        ]);

        return response()->json($this->uploads->complete($media, $data['parts'] ?? [])->toPayload());
    }
}
