<?php

namespace App\Modules\Campaigns\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Campaigns\Models\CampaignRecipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CampaignTrackingController extends Controller
{
    /**
     * POST /v1/campaigns/{recipient}/opened { clicked?: bool, channel?: push|in_app }
     *
     * {recipient} is the `recipient_id` from the push data or the in-app notification. A
     * push that went to many people at once carries only `campaign_id`; pass that instead
     * and the signed-in user's message on `channel` (default push) is marked.
     */
    public function opened(Request $request, string $recipient): JsonResponse
    {
        $data = $request->validate([
            'clicked' => ['sometimes', 'boolean'],
            'channel' => ['sometimes', Rule::in(['push', 'in_app'])],
        ]);
        $userId = $request->user()->id;

        $row = CampaignRecipient::where('user_id', $userId)->whereKey($recipient)->first()
            ?? CampaignRecipient::where('user_id', $userId)->where('campaign_id', $recipient)
                ->where('channel', $data['channel'] ?? 'push')
                ->whereNotNull('sent_at')->latest('sent_at')->first();
        abort_if($row === null, 404);

        $now = now();
        $row->opened_at ??= $now;
        if ($request->boolean('clicked')) {
            $row->clicked_at ??= $now;
        }
        $row->save();

        return response()->json(['opened_at' => $row->opened_at?->toIso8601String(), 'clicked_at' => $row->clicked_at?->toIso8601String()]);
    }
}
