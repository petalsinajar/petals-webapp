<?php

namespace App\Modules\Media\Video;

use App\Modules\Media\Data\VideoStatus;
use Illuminate\Http\Request;

/**
 * Cloudflare Stream's webhook format, shared by the real provider and the fake.
 *
 * Signature header:  Webhook-Signature: time=1730000000,sig1=<hex hmac>
 * where sig1 = HMAC-SHA256(secret, "{time}.{raw body}"). Old timestamps are refused.
 */
final class StreamWebhook
{
    public static function verify(Request $request, ?string $secret, int $toleranceSeconds): bool
    {
        if (blank($secret)) {
            return false;
        }

        parse_str(str_replace(',', '&', (string) $request->header('Webhook-Signature')), $parts);
        $time = $parts['time'] ?? null;
        $signature = $parts['sig1'] ?? null;

        if (! is_string($time) || ! ctype_digit($time) || ! is_string($signature)) {
            return false;
        }
        if (abs(time() - (int) $time) > $toleranceSeconds) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $time.'.'.$request->getContent(), $secret), $signature);
    }

    /** The header value for a body, e.g. to send test webhooks. */
    public static function sign(string $body, string $secret, ?int $time = null): string
    {
        $time ??= time();

        return "time={$time},sig1=".hash_hmac('sha256', $time.'.'.$body, $secret);
    }

    /**
     * A Stream video object (webhook body or GET /stream/{uid} result) as a VideoStatus.
     *
     * @param  array<string, mixed>  $video
     */
    public static function status(array $video): VideoStatus
    {
        $uid = (string) ($video['uid'] ?? '');
        $state = match ($video['status']['state'] ?? null) {
            'ready' => VideoStatus::READY,
            'error' => VideoStatus::FAILED,
            'pendingupload', null => VideoStatus::PENDING_UPLOAD,
            default => VideoStatus::PROCESSING, // downloading | queued | inprogress
        };
        if ($state === VideoStatus::READY && ! ($video['readyToStream'] ?? true)) {
            $state = VideoStatus::PROCESSING;
        }
        $duration = $video['duration'] ?? null;

        return new VideoStatus(
            ref: $uid,
            state: $state,
            bytes: isset($video['size']) ? (int) $video['size'] : null,
            durationMs: is_numeric($duration) && $duration >= 0 ? (int) round($duration * 1000) : null,
            width: isset($video['input']['width']) && $video['input']['width'] > 0 ? (int) $video['input']['width'] : null,
            height: isset($video['input']['height']) && $video['input']['height'] > 0 ? (int) $video['input']['height'] : null,
            hlsPath: $state === VideoStatus::READY ? "{$uid}/manifest/video.m3u8" : null,
            posterPath: $state === VideoStatus::READY ? "{$uid}/thumbnails/thumbnail.jpg" : null,
            error: $video['status']['errReasonText'] ?? $video['status']['errorReasonText'] ?? null,
        );
    }
}
