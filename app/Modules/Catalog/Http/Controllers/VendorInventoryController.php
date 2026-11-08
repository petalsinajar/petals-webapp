<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Availability\AvailabilityStore;
use App\Modules\Catalog\Availability\ReservationKind;
use App\Modules\Catalog\Availability\ReservationRequest;
use App\Modules\Catalog\Http\Resources\ItemResource;
use App\Modules\Catalog\Http\Resources\PackageResource;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemVariant;
use App\Modules\Catalog\Models\Package;
use App\Modules\Catalog\Services\CapacityWarnings;
use App\Modules\Identity\Models\Vendor;
use App\Support\Paginated;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Vendor mode: inventory, stock and calendar blocks. */
class VendorInventoryController extends Controller
{
    public function __construct(
        private readonly AvailabilityStore $store,
        private readonly CapacityWarnings $warnings,
    ) {}

    /** GET /v1/vendors/{vendor}/items — every status, for the vendor's own inventory screen. */
    public function index(Request $request, Vendor $vendor): JsonResponse
    {
        Gate::authorize('operate', $vendor);

        $items = Item::with('variants')->where('vendor_id', $vendor->id)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('title')
            ->get();

        return Paginated::manual($items, null, ItemResource::class);
    }

    /** POST /v1/vendors/{vendor}/items — the item and its variants. */
    public function store(Request $request, Vendor $vendor): JsonResponse
    {
        Gate::authorize('manage', $vendor);
        $data = $request->validate($this->itemRules());

        $item = DB::transaction(function () use ($data, $vendor) {
            $item = new Item($this->itemAttributes($data) + ['currency' => config('eventapp.currency')]);
            $item->vendor_id = $vendor->id;
            $item->save();
            $this->syncVariants($item, $data['variants']);

            return $item;
        });

        return (new ItemResource($item->load('variants')))->response()->setStatusCode(201);
    }

    /**
     * PATCH /v1/vendors/{vendor}/items/{item}
     *
     * `variants`, when sent, is the full list: entries with an `id` update that variant, entries
     * without one are added, and variants left out are removed (or deactivated if bookings,
     * reservations, packages or posts point at them). Lowering a quantity below what's already
     * reserved is allowed but comes back in `warnings`.
     */
    public function update(Request $request, Vendor $vendor, string $item): JsonResponse
    {
        Gate::authorize('manage', $vendor);
        $model = $this->ownedItem($vendor, $item);
        $data = $request->validate($this->itemRules(partial: true));

        DB::transaction(function () use ($model, $data) {
            $model->fill($this->itemAttributes($data))->save();
            if (array_key_exists('variants', $data)) {
                $this->syncVariants($model, $data['variants']);
            }
        });

        $warnings = array_map(fn (array $w) => [
            'variant_id' => $w['variant_id'],
            'days' => $w['days'],
            'message' => 'More units are already booked than you now have on these days.',
        ], $this->warnings->pull($model->id));

        return response()->json([...(new ItemResource($model->load('variants')))->resolve(), 'warnings' => $warnings]);
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
            'lines.*.item_id' => ['required', 'ulid'],
            'lines.*.variant_id' => ['required', 'ulid'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'price_minor' => ['required', 'integer', 'min:0'],
            'media' => ['array', 'max:10'],
        ]);

        foreach ($data['lines'] as $i => $line) {
            $this->ownedVariant($vendor, $line['item_id'], $line['variant_id'], "lines.$i.variant_id");
        }

        $package = DB::transaction(function () use ($data, $vendor) {
            $package = Package::create([
                ...Arr::except($data, 'lines'),
                'vendor_id' => $vendor->id,
                'currency' => config('eventapp.currency'),
            ]);
            foreach ($data['lines'] as $line) {
                $package->lines()->create([
                    'item_id' => strtolower($line['item_id']),
                    'variant_id' => strtolower($line['variant_id']),
                    'qty' => $line['qty'],
                ]);
            }

            return $package;
        });

        return new PackageResource($package->load('lines'));
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
            'item_id' => ['required', 'ulid'],
            'variant_id' => ['required', 'ulid'],
            'qty' => ['required', 'integer', 'min:1'],
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'reason' => ['required', Rule::in(['offline_booking', 'maintenance', 'lost'])],
        ]);

        $variant = $this->ownedVariant($vendor, $data['item_id'], $data['variant_id']);

        [$reservation] = $this->store->reserveMany([new ReservationRequest(
            vendorId: $vendor->id,
            itemId: $variant->item_id,
            variantId: $variant->id,
            qty: $data['qty'],
            capacity: $variant->quantity,
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

    /**
     * Make the item's variants match the list. ItemVariant's hooks take care of the stock
     * counters (quantity changes) and of keeping used variants (deactivated, not deleted).
     *
     * @param  list<array<string, mixed>>  $variants
     */
    private function syncVariants(Item $item, array $variants): void
    {
        $existing = $item->variants()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($variants) as $sort => $input) {
            $attributes = [...Arr::only($input, ['name', 'sku', 'quantity', 'price_delta_minor', 'active']), 'sort' => $sort];

            if (isset($input['id'])) {
                $variant = $existing->get(strtolower($input['id']))
                    ?? throw ValidationException::withMessages(["variants.$sort.id" => 'This variant belongs to another item.']);
                $variant->fill($attributes)->save();
            } else {
                $variant = $item->variants()->create($attributes);
            }
            $kept[] = $variant->id;
        }

        foreach ($existing->except($kept) as $removed) {
            $removed->delete();
        }
    }

    /**
     * Request fields to item columns. `pricing` is one object in the API.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function itemAttributes(array $data): array
    {
        $attributes = Arr::except($data, ['variants', 'pricing']);

        if (isset($data['pricing'])) {
            $attributes['pricing_unit'] = $data['pricing']['unit'];
            $attributes['price_tiers'] = array_values($data['pricing']['tiers']);
            if (isset($data['pricing']['currency'])) {
                $attributes['currency'] = strtoupper($data['pricing']['currency']);
            }
        }

        return $attributes;
    }

    private function ownedItem(Vendor $vendor, string $id): Item
    {
        $item = Str::isUlid($id) ? Item::find(strtolower($id)) : null;
        abort_if(! $item || $item->vendor_id !== $vendor->id, 404, 'Item not found.');

        return $item;
    }

    private function ownedVariant(Vendor $vendor, string $itemId, string $variantId, string $field = 'variant_id'): ItemVariant
    {
        $item = $this->ownedItem($vendor, $itemId);

        return $item->variants()->find(strtolower($variantId))
            ?? throw ValidationException::withMessages([$field => 'Unknown variant.']);
    }

    /** @return array<string, mixed> */
    private function itemRules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'category_id' => [$req, 'string', 'max:40', Rule::exists('categories', 'id')],
            'title' => [$req, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'media' => ['sometimes', 'array', 'max:12'],
            'media.*.url' => ['required', 'url', 'max:500'],
            'media.*.type' => ['required', Rule::in(['image', 'video'])],
            'media.*.w' => ['nullable', 'integer'],
            'media.*.h' => ['nullable', 'integer'],
            'media.*.blurhash' => ['nullable', 'string', 'max:100'],
            'specs' => ['sometimes', 'nullable', 'array'],
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
            'variants.*.id' => ['sometimes', 'ulid', 'distinct'],
            'variants.*.name' => ['required', 'string', 'max:60'],
            'variants.*.sku' => ['nullable', 'string', 'max:40'],
            'variants.*.quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'variants.*.price_delta_minor' => ['sometimes', 'integer'],
            'variants.*.active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'paused'])],
        ];
    }
}
