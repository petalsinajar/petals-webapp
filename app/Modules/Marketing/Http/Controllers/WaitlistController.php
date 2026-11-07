<?php

namespace App\Modules\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketing\Enums\WaitlistRole;
use App\Modules\Marketing\Services\WaitlistService;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WaitlistController extends Controller
{
    /** Hidden from people; bots fill it in. */
    public const HONEYPOT = 'website';

    public function __construct(private readonly WaitlistService $waitlist) {}

    /**
     * POST /waitlist — answers JSON to the landing page script, and redirects
     * back with a flash message when the form is posted without JavaScript.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $role = WaitlistRole::tryFrom((string) $request->input('role'));

        // Look like a normal success so bots don't learn to skip the field.
        if (filled($request->input(self::HONEYPOT))) {
            return $this->joined($request, $role ?? WaitlistRole::Host, created: true);
        }

        $isVendor = $role === WaitlistRole::Vendor;
        $marketing = config('eventapp.marketing');

        $data = $request->validateWithBag($isVendor ? 'vendor' : 'host', [
            'role' => ['required', Rule::enum(WaitlistRole::class)],
            'name' => ['required', 'string', 'max:80'],
            'whatsapp_phone' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || PhoneNumber::normalize($value) === null) {
                    $fail('Enter a WhatsApp number we can reach, like 024 123 4567.');
                }
            }],
            'city' => ['required', 'string', 'max:80'],
            'business_name' => [Rule::requiredIf($isVendor), 'nullable', 'string', 'max:120'],
            'category' => [Rule::requiredIf($isVendor), 'nullable', Rule::in(array_keys($marketing['vendor_categories']))],
            'event_types' => ['nullable', 'array'],
            'event_types.*' => ['distinct', Rule::in(array_keys($marketing['event_types']))],
            'utm_source' => ['nullable', 'string', 'max:100'],
            'utm_medium' => ['nullable', 'string', 'max:100'],
            'utm_campaign' => ['nullable', 'string', 'max:100'],
        ], [
            'business_name.required' => 'Tell us your business name.',
            'category.required' => 'Pick what you rent out.',
        ]);

        [, $created] = $this->waitlist->join($role, [
            'name' => trim($data['name']),
            'whatsapp_phone' => PhoneNumber::normalize($data['whatsapp_phone']),
            'city' => trim($data['city']),
            'business_name' => isset($data['business_name']) ? trim($data['business_name']) : null,
            'category' => $data['category'] ?? null,
            'event_types' => array_values($data['event_types'] ?? []),
            'source' => array_filter([
                'utm_source' => $data['utm_source'] ?? null,
                'utm_medium' => $data['utm_medium'] ?? null,
                'utm_campaign' => $data['utm_campaign'] ?? null,
            ]),
        ]);

        return $this->joined($request, $role, $created);
    }

    private function joined(Request $request, WaitlistRole $role, bool $created): JsonResponse|RedirectResponse
    {
        $message = match (true) {
            ! $created => 'You’re already on the list. We’ll message you on WhatsApp soon.',
            $role === WaitlistRole::Vendor => 'Thanks! We’ll message you on WhatsApp to set up your shop.',
            default => 'You’re on the list! We’ll message you on WhatsApp when we launch.',
        };

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'role' => $role->value,
                'already_joined' => ! $created,
            ], $created ? 201 : 200);
        }

        return redirect()
            ->to(url('/').($role === WaitlistRole::Vendor ? '#vendors' : '#waitlist'))
            ->with('waitlist_joined', ['role' => $role->value, 'message' => $message]);
    }
}
