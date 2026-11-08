<?php

namespace App\Modules\Bookings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bookings\Http\Resources\BookingResource;
use App\Modules\Bookings\Models\Checkout;
use App\Modules\Bookings\Services\CheckoutService;
use App\Modules\Events\Models\Event;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    /** POST /v1/checkouts — creates one booking per vendor and holds the stock. */
    public function store(Request $request, CheckoutService $checkouts): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'string', 'size:26'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.item_id' => ['required', 'ulid'],
            'lines.*.variant_id' => ['required', 'ulid'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:100000'],
            'fulfilment' => ['sometimes', 'array'],
            'fulfilment.*.type' => ['sometimes', Rule::in(['delivery', 'pickup'])],
            'fulfilment.*.address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'fulfilment.*.lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'fulfilment.*.lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'fulfilment.*.setup' => ['sometimes', 'boolean'],
            'fulfilment.*.deliver_at' => ['sometimes', 'date'],
            'fulfilment.*.collect_at' => ['sometimes', 'date'],
            'fulfilment.*.contact_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'notes' => ['sometimes', 'array'],
            'notes.*' => ['string', 'max:1000'],
        ]);

        $event = Event::findOrFail($data['event_id']);
        Gate::authorize('manage', $event);

        $checkout = $checkouts->create($request->user(), $event, $data);

        return response()->json($this->present($checkout), 201);
    }

    /** GET /v1/checkouts/{checkout} */
    public function show(Request $request, Checkout $checkout): JsonResponse
    {
        abort_unless($checkout->customer_id === $request->user()->id, 404);

        return response()->json($this->present($checkout->load('bookings.lines', 'bookings.vendor')));
    }

    /** POST /v1/checkouts/{checkout}/pay — header Idempotency-Key recommended. */
    public function pay(Request $request, Checkout $checkout, PaymentService $payments): JsonResponse
    {
        abort_unless($checkout->customer_id === $request->user()->id, 404);
        $data = $request->validate(['purpose' => ['required', Rule::in(['deposit', 'full', 'balance'])]]);

        $result = $payments->start($checkout, $request->user(), $data['purpose'], $request->header('Idempotency-Key'));

        return response()->json([
            'payment_id' => $result['payment']->id,
            'reference' => $result['payment']->provider_ref,
            'amount_minor' => $result['payment']->amount_minor,
            'currency' => $result['payment']->currency,
            'allocations' => $result['payment']->allocations,
            'authorization_url' => $result['authorization_url'],
            'status' => $result['payment']->status,
        ], 201);
    }

    /** @return array<string, mixed> */
    private function present(Checkout $checkout): array
    {
        return [
            'id' => $checkout->id,
            'event_id' => $checkout->event_id,
            'status' => $checkout->status,
            'total_minor' => $checkout->total_minor,
            'currency' => $checkout->currency,
            'bookings' => BookingResource::collection($checkout->bookings)->resolve(),
        ];
    }
}
