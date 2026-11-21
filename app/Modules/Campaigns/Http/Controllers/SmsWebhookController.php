<?php

namespace App\Modules\Campaigns\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Campaigns\Contracts\SmsProvider;
use App\Modules\Campaigns\Enums\RecipientStatus;
use App\Modules\Campaigns\Models\CampaignRecipient;
use App\Modules\Campaigns\Services\Preferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The SMS provider's callbacks, authenticated with SMS_WEBHOOK_TOKEN (see authorizeToken). Without a token
 * set, both are refused. Unknown message ids and unreadable bodies are acknowledged and
 * ignored, so the provider doesn't keep retrying them.
 */
class SmsWebhookController extends Controller
{
    public function __construct(private readonly SmsProvider $sms) {}

    /** POST /v1/webhooks/sms/delivery */
    public function delivery(Request $request): JsonResponse
    {
        $this->authorizeToken($request);
        $report = $this->sms->deliveryReport($request);
        if ($report === null) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $recipient = CampaignRecipient::where('provider_message_id', $report->messageId)->where('channel', 'sms')->first();
        if ($recipient && in_array($recipient->status, [RecipientStatus::Sent, RecipientStatus::Queued], true)) {
            $recipient->forceFill($report->delivered
                ? ['status' => RecipientStatus::Delivered, 'delivered_at' => now()]
                : ['status' => RecipientStatus::Failed, 'error' => mb_substr('Delivery report: '.$report->status, 0, 255)])->save();
        }

        return response()->json(['ok' => true]);
    }

    /** POST /v1/webhooks/sms/inbound — a reply. STOP (and friends) opts the number out of marketing SMS. */
    public function inbound(Request $request, Preferences $preferences): JsonResponse
    {
        $this->authorizeToken($request);
        $sms = $this->sms->inbound($request);
        if ($sms === null) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $word = strtoupper(trim((string) strtok(trim($sms->text), " \t\n.!")));
        $stop = in_array($word, array_map('strtoupper', config('eventapp.campaigns.sms.stop_words')), true);
        if ($stop) {
            $preferences->stopSms($sms->from);
        }

        return response()->json(['ok' => true, 'opted_out' => $stop]);
    }

    /**
     * The shared secret, from the X-Webhook-Token header or the HTTP Basic password when the
     * provider can send them; ?token= only for providers that can just call a URL (it ends up
     * in access logs, so redact it there). SMS_WEBHOOK_TOKEN may list several, comma-separated,
     * so the secret can be rotated without downtime.
     */
    private function authorizeToken(Request $request): void
    {
        $tokens = array_filter(array_map('trim', explode(',', (string) config('eventapp.campaigns.sms.webhook_token'))));
        $given = (string) ($request->header('X-Webhook-Token') ?: $request->getPassword() ?: $request->query('token'));

        $ok = $given !== '' && array_filter($tokens, fn (string $token) => hash_equals($token, $given)) !== [];
        abort_unless($ok, 403);
    }
}
