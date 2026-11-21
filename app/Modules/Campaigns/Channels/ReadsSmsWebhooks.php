<?php

namespace App\Modules\Campaigns\Channels;

use App\Modules\Campaigns\Data\InboundSms;
use App\Modules\Campaigns\Data\SmsDeliveryReport;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;

/**
 * Delivery reports and inbound replies, read from the field names Ghanaian gateways
 * commonly use (JSON or form). CB Messaging's exact webhook format isn't documented here:
 * check a real callback against these names before relying on them.
 */
trait ReadsSmsWebhooks
{
    public function deliveryReport(Request $request): ?SmsDeliveryReport
    {
        $id = $this->first($request, ['message_id', 'messageId', 'MessageId', 'sms_id', 'id']);
        $status = $this->first($request, ['status', 'Status', 'dlr_status', 'delivery_status']);
        if ($id === null || $status === null) {
            return null;
        }
        $delivered = in_array(strtolower($status), ['delivered', 'delivrd', 'success', 'successful', 'sent_to_handset'], true);

        return new SmsDeliveryReport($id, $delivered, $status);
    }

    public function inbound(Request $request): ?InboundSms
    {
        $from = $this->first($request, ['from', 'From', 'sender', 'msisdn', 'phone']);
        $text = $this->first($request, ['message', 'msg', 'text', 'Text', 'content', 'body']);
        $phone = $from === null ? null : PhoneNumber::normalize($from);

        return $phone === null || $text === null ? null : new InboundSms($phone, $text);
    }

    /** @param list<string> $keys */
    private function first(Request $request, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $request->input($key);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}
