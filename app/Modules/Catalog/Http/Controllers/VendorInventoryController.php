<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Availability\AvailabilityStore;
use App\Modules\Catalog\Availability\ReservationKind;
use App\Modules\Catalog\Availability\ReservationRequest;
use App\Modules\Catalog\Availability\ReservationWindow;
use App\Modules\Catalog\Http\Resources\ItemResource;
use App\Modules\Catalog\Http\Resources\PackageResource;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Package;
use App\Modules\Identity\Models\Vendor;
use App\Support\Paginated;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Vendor mode: inventory, stock and calendar blocks. */
class VendorInventoryController extends Controller
{
    public function __construct(private readonly AvailabilityStore $store) {}

    /** GET /v1/vendors/{vendor}/items — every status, for the vendor's own inventory screen. */
    public function index(Request $request, Vendor $vendor): JsonResponse
    {
        Gate::authorize('operate', $vendor);

        $items = Item::where('vendor_id', $vendor->id)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('title')
            ->get();

        return Paginated::manual($items, null, ItemResource::class);
    }

    /** POST /v1/vendors/{vendor}/items */
    public function store(Request $request, Vendor $vendor): ItemResource
    {
        Gate::authorize('manage', $vendor);
        $data = $request->validate($this->itemRules());

        $item = new Item($data);
        $item->vendor_id = $vendor->id;
        $item->geo = $this->geoFor($vendor);
        $item->save();

        return new ItemResource($item);
    }

    /** PATCH /v1/vendors/{vendor}/items/{item} */
    public function update(Request $request, Vendor $vendor, string $item): JsonResponse
    {
        Gate::authorize('manage', $vendor);
        $model = $this->ownedItem($vendor, $item);
        $data = $request->validate($this->itemRules(partial: true));

        // Item's updated hook pushes quantity changes into the availability counters.
        $model->fill($data);
        $model->save();

        $warnings = array_map(fn (array $w) => [
            ...$w,
            'message' => 'More units are already booked than you now have on these days.',
        ], $model->capacityWarnings);

        return response()->json([...(new ItemResource($model))->resolve(), 'warnings' => $warnings]);
    }

    /** DELETE /v1/vendors/{vendor}/items/{item} — archived, never hard-deleted (bookings point at it). */
    public function destroy(Vendor $vendor, string $item): JsonResponse
    {
        Gate::authorize('manage', $vendor);
        $this->ownedItem($vendor, $item)->update(['status' => 'archived']);

        return response()->json(['message' => 'Item archived.']);
    }

    /** POST /v1/vendors/{vendor}/packages */
    public function storePackage(Request $request, Vendor $vendor): PackageResource
    {
        Gate::authorize('manage', $vendor);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'event_types' => ['array'],
            'event_types.*' => [Rule::in(['wedding', 'birthday', 'corporate', 'other'])],
            'guest_min' => ['nullable', 'integer', 'min:1'],
            'guest_max' => ['nullable', 'integer', 'gte:guest_min'],
            'lines' => ['required', 'array', 'min:1', 'max:30'],
            'lines.*.item_id' => ['required', 'string', 'size:24'],
            'lines.*.variant_id' => ['required', 'string', 'size:24'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'price_minor' => ['required', 'integer', 'min:0'],
            'media' => ['array', 'max:10'],
        ]);

        foreach ($data['lines'] as $i => $line) {
            $item = $this->ownedItem($vendor, $line['item_id']);
            if (! $item->variant($line['variant_id'])) {
                throw ValidationException::withMessages(["lines.$i.variant_id" => 'Unknown variant.']);
            }
        }

        $package = Package::create([...$data, 'vendor_id' => $vendor->id, 'currency' => config('eventapp.currency')]);

        return new PackageResource($package);
    }

    /** GET /v1/vendors/{vendor}/calendar?from=&to= — bookings, holds and blocks. */
    public function calendar(Request $request, Vendor $vendor): JsonResponse
    {
        Gate::authorize('operate', $vendor);
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $tz = config('eventapp.business_timezone');

        $reservations = $this->store->forVendor(
            $vendor->id,
            CarbonImmutable::parse($data['from'], $tz),
            CarbonImmutable::parse($data['to'], $tz)->addDay(),
        );

        return response()->json(['data' => array_map(fn ($r) => $r->toArray(), $reservations)]);
    }

    /** POST /v1/vendors/{vendor}/blocks — stock taken by a phone order, maintenance, or loss. */
    public function block(Request $request, Vendor $vendor): JsonResponse
    {
        Gate::authorize('operate', $vendor);
        $data = $request->validate([
            'item_id' => ['required', 'string', 'size:24'],
            'variant_id' => ['required', 'string', 'size:24'],
            'qty' => ['required', 'integer', 'min:1'],
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'reason' => ['required', Rule::in(['offline_booking', 'maintenance', 'lost'])],
        ]);

        $item = $this->ownedItem($vendor, $data['item_id']);
        $variant = $item->variant($data['variant_id']) ?? throw ValidationException::withMessages(['variant_id' => 'Unknown variant.']);

        [$reservation] = $this->store->reserveMany([new ReservationRequest(
            vendorId: $vendor->id,
            itemId: $item->id,
            variantId: $data['variant_id'],
            qty: $data['qty'],
            capacity: (int) $variant['quantity'],
            start: CarbonImmutable::parse($data['start']),
            end: CarbonImmutable::parse($data['end']),
            kind: ReservationKind::Block,
            reason: $data['reason'],
        )]);

        return response()->json($reservation->toArray(), 201);
    }

    /** DELETE /v1/vendors/{vendor}/blocks/{reservation} */
    public function unblock(Vendor $vendor, string $reservation): JsonResponse
    {
        Gate::authorize('operate', $vendor);
        $found = $this->store->find($reservation);
        abort_if(! $found || $found->vendorId !== $vendor->id || $found->kind !== ReservationKind::Block, 404);

        $this->store->release($found->id);

        return response()->json(['message' => 'Block removed.']);
    }

    // ---------------------------------------------------------------------

    private function ownedItem(Vendor $vendor, string $id): Item
    {
        $item = preg_match('/^[a-f0-9]{24}$/', $id) ? Item::find($id) : null;
        abort_if(! $item || $item->vendor_id !== $vendor->id, 404, 'Item not found.');

        return $item;
    }

    /** @return array{type: string, coordinates: array{float, float}}|null */
    private function geoFor(Vendor $vendor): ?array
    {
        return $vendor->base_lat !== null && $vendor->base_lng !== null
            ? ['type' => 'Point', 'coordinates' => [(float) $vendor->base_lng, (float) $vendor->base_lat]]
            : null;
    }

    /** @return array<string, mixed> */
    private function itemRules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'category_id' => [$req, 'string', 'max:40'],
            'title' => [$req, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'media' => ['sometimes', 'array', 'max:12'],
            'media.*.url' => ['required', 'url', 'max:500'],
            'media.*.type' => ['required', Rule::in(['image', 'video'])],
            'media.*.w' => ['nullable', 'integer'],
            'media.*.h' => ['nullable', 'integer'],
            'media.*.blurhash' => ['nullable', 'string', 'max:100'],
            'item_attributes' => ['sometimes', 'array'],
            'event_types' => ['sometimes', 'array'],
            'event_types.*' => [Rule::in(['wedding', 'birthday', 'corporate', 'other'])],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:30'],
            'pricing' => [$req, 'array:unit,currency,tiers'],
            'pricing.unit' => ['required_with:pricing', Rule::in(['per_event', 'per_day', 'per_hour'])],
            'pricing.currency' => ['sometimes', 'string', 'size:3'],
            'pricing.tiers' => ['required_with:pricing', 'array', 'min:1', 'max:10'],
            'pricing.tiers.*.min_qty' => ['required', 'integer', 'min:1'],
            'pricing.tiers.*.price_minor' => ['required', 'integer', 'min:0'],
            'deposit_policy' => ['sometimes', 'nullable', 'array:type,value'],
            'damage_deposit_minor' => ['sometimes', 'integer', 'min:0'],
            'min_qty' => ['sometimes', 'integer', 'min:1'],
            'requires_setup' => ['sometimes', 'boolean'],
            'instant_book' => ['sometimes', 'boolean'],
            'variants' => [$req, 'array', 'min:1', 'max:50'],
            'variants.*.variant_id' => ['sometimes', 'string', 'size:24'],
            'variants.*.name' => ['required', 'string', 'max:60'],
            'variants.*.sku' => ['nullable', 'string', 'max:40'],
            'variants.*.quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'variants.*.price_delta_minor' => ['sometimes', 'integer'],
            'variants.*.active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'paused'])],
        ];
    }
}
