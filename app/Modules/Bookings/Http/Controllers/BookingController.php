<?php

namespace App\Modules\Bookings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bookings\Enums\BookingStatus;
use App\Modules\Bookings\Http\Resources\BookingResource;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingService;
use App\Modules\Bookings\Services\RecordedBookingService;
use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\Vendor;
use App\Support\Paginated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Booking actions are separate verbs, so each can check who may do it and from which state.
 */
class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    /** GET /v1/bookings/{booking} */
    public function show(Booking $booking): BookingResource
    {
        Gate::authorize('view', $booking);

        return new BookingResource($booking->load('lines', 'vendor', 'customer', 'statusEvents'));
    }

    /** GET /v1/me/bookings?status= */
    public function mine(Request $request): JsonResponse
    {
        $bookings = Booking::where('customer_id', $request->user()->id)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->with('vendor', 'lines')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate(20);

        return Paginated::response($bookings, BookingResource::class);
    }

    /** GET /v1/events/{event}/bookings */
    public function forEvent(Request $request, string $event): JsonResponse
    {
        $model = Event::findOrFail($event);
        Gate::authorize('manage', $model);

        $bookings = Booking::where('event_id', $model->id)->with('vendor', 'lines')
            ->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(50);

        return Paginated::response($bookings, BookingResource::class);
    }

    /** GET /v1/vendors/{vendor}/bookings?status=&from=&to= — the vendor's schedule. */
    public function forVendor(Request $request, Vendor $vendor): JsonResponse
    {
        Gate::authorize('operate', $vendor);
        $data = $request->validate([
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(BookingStatus::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $bookings = Booking::where('vendor_id', $vendor->id)
            ->when($data['status'] ?? null, fn ($q, $s) => $q->whereIn('status', $s))
            ->when($data['from'] ?? null, fn ($q, $from) => $q->where('window_end', '>=', $from))
            ->when($data['to'] ?? null, fn ($q, $to) => $q->where('window_start', '<=', $to))
            ->with('customer', 'lines')
            ->orderBy('window_start')->orderBy('id')
            ->cursorPaginate(50);

        return Paginated::response($bookings, BookingResource::class);
    }

    /** POST /v1/vendors/{vendor}/bookings — a booking agreed outside the app (phone, walk-in). */
    public function record(Request $request, Vendor $vendor, RecordedBookingService $recorder): JsonResponse
    {
        Gate::authorize('manage', $vendor);
        $data = $request->validate([
            'customer.name' => ['required', 'string', 'max:120'],
            'customer.phone' => ['required', 'string', 'max:30'],
            'deliver_at' => ['required', 'date'],
            'collect_at' => ['required', 'date'],
            'fulfilment.type' => ['required', Rule::in(['pickup', 'delivery'])],
            'fulfilment.address' => ['nullable', 'required_if:fulfilment.type,delivery', 'string', 'max:300'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.item_id' => ['required', 'string'],
            'lines.*.variant_id' => ['required', 'string'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'delivery_minor' => ['nullable', 'integer', 'min:0'],
            'paid_minor' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->present($recorder->record($vendor, $request->user(), $data))->response()->setStatusCode(201);
    }

    public function accept(Request $request, Booking $booking): BookingResource
    {
        return $this->present($this->bookings->accept($booking, $request->user()));
    }

    public function decline(Request $request, Booking $booking): BookingResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->present($this->bookings->decline($booking, $request->user(), $data['reason'] ?? null));
    }

    public function cancel(Request $request, Booking $booking): BookingResource
    {
        Gate::authorize('view', $booking);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->present($this->bookings->cancel($booking, $request->user(), $data['reason'] ?? null));
    }

    public function markDispatched(Request $request, Booking $booking): BookingResource
    {
        return $this->present($this->bookings->dispatch($booking, $request->user()));
    }

    public function deliver(Request $request, Booking $booking): BookingResource
    {
        Gate::authorize('view', $booking);
        $data = $request->validate(['photos' => ['sometimes', 'array', 'max:10'], 'photos.*' => ['url']]);

        return $this->present($this->bookings->deliver($booking, $request->user(), $data['photos'] ?? []));
    }

    public function markReturned(Request $request, Booking $booking): BookingResource
    {
        $data = $request->validate([
            'damage_claim_minor' => ['sometimes', 'integer', 'min:0'],
            'photos' => ['sometimes', 'array', 'max:10'],
            'photos.*' => ['url'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->present($this->bookings->markReturned(
            $booking, $request->user(), $data['damage_claim_minor'] ?? 0, $data['photos'] ?? [], $data['note'] ?? null,
        ));
    }

    private function present(Booking $booking): BookingResource
    {
        return new BookingResource($booking->load('lines', 'vendor', 'customer', 'statusEvents'));
    }
}
