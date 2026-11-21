<?php

namespace App\Modules\Campaigns\Contracts;

use App\Modules\Campaigns\Data\InboundSms;
use App\Modules\Campaigns\Data\SmsDeliveryReport;
use App\Modules\Campaigns\Data\SmsResult;
use App\Modules\Identity\Exceptions\SmsDeliveryFailed;
use Illuminate\Http\Request;

/** Campaign SMS: sending with the registered sender ID, and the provider's webhooks. */
interface SmsProvider
{
    /** @throws SmsDeliveryFailed when the provider refuses or can't be reached */
    public function send(string $phone, string $message): SmsResult;

    /** A delivery report webhook, or null if the request isn't one. */
    public function deliveryReport(Request $request): ?SmsDeliveryReport;

    /** An inbound reply webhook (e.g. STOP), or null if the request isn't one. */
    public function inbound(Request $request): ?InboundSms;
}
