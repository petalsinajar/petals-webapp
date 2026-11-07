<?php

namespace App\Modules\Identity\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** The SMS gateway didn't accept a message. The reason is for logs only. */
class SmsDeliveryFailed extends RuntimeException
{
    /** A 503 the app shows as-is, so the user retries instead of waiting for a code. */
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => "We couldn't send the text message. Please try again in a moment.",
        ], 503);
    }
}
