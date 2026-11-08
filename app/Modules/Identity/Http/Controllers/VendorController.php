<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Enums\VendorRole;
use App\Modules\Identity\Http\Resources\VendorResource;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Identity\Services\HandleRegistry;
use App\Support\Actor;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class VendorController extends Controller
{
    private const EVENT_TYPES = ['wedding', 'birthday', 'corporate', 'other'];

    public function __construct(private readonly HandleRegistry $handles) {}

    /**
     * GET /v1/vendors?lat=&lng=&category=&event_type= — vendors whose service area covers that
     * venue, nearest first. One page of at most `limit`.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'category' => ['nullable', 'string', 'max:40'],
            'event_type' => ['nullable', Rule::in(self::EVENT_TYPES)],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);
        [$lat, $lng] = [(float) $data['lat'], (float) $data['lng']];

        $query = Vendor::query()
            ->where('status', 'active')
            ->when($data['category'] ?? null, fn ($q, $category) => $q->whereJsonContains('categories', $category))
            ->when($data['event_type'] ?? null, fn ($q, $type) => $q->whereJsonContains('event_types', $type));
        Geo::vendorsServing($query, $lat, $lng);
        Geo::orderByDistance($query, $lat, $lng);

        $vendors = $query->orderBy('id')->limit($data['limit'] ?? 20)->get();

        return response()->json([
            'data' => $vendors->map(fn (Vendor $vendor) => [
                ...(new VendorResource($vendor))->resolve(),
                'distance_km' => round(Geo::distanceKm($vendor->base_lat, $vendor->base_lng, $lat, $lng), 1),
            ])->values(),
            'next_cursor' => null,
        ]);
    }

    /** POST /v1/vendors — the signed-in user becomes the owner. */
    public function store(Request $request): VendorResource
    {
        $data = $request->validate([
            ...$this->rules(),
            'handle' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $vendor = DB::transaction(function () use ($data, $request) {
            $vendor = new Vendor(collect($data)->except('handle')->all());
            $vendor->handle = HandleRegistry::normalize($data['handle']);
            $vendor->save();
            $this->handles->claim($vendor->handle, $vendor);
            $vendor->members()->create(['user_id' => $request->user()->id, 'role' => VendorRole::Owner, 'status' => 'active']);

            return $vendor;
        });

        return new VendorResource($vendor->setAttribute('my_role', 'owner'));
    }

    /** GET /v1/vendors/{vendor} — by id or handle. */
    public function show(Request $request, string $vendor): VendorResource
    {
        $model = Vendor::where('id', $vendor)->orWhere('handle', HandleRegistry::normalize($vendor))->firstOrFail();
        $me = $request->user();

        $model->setAttribute('is_following', DB::table('follows')
            ->where(['follower_type' => Actor::USER, 'follower_id' => $me->id, 'target_type' => Actor::VENDOR, 'target_id' => $model->id])
            ->exists());
        $model->setAttribute('my_role', $me->roleIn($model)?->value);

        return new VendorResource($model);
    }

    /** PATCH /v1/vendors/{vendor} */
    public function update(Request $request, Vendor $vendor): VendorResource
    {
        Gate::authorize('update', $vendor);

        $data = $request->validate($this->rules(partial: true));

        // Policies and payout details are owner-only.
        if (array_key_exists('policies', $data) || array_key_exists('payout', $data)) {
            Gate::authorize('own', $vendor);
        }

        if (isset($data['handle'])) {
            $this->handles->rename($vendor, $data['handle']);
            unset($data['handle']);
        }

        $vendor->fill($data)->save();

        return new VendorResource($vendor->setAttribute('my_role', $request->user()->roleIn($vendor)?->value));
    }

    /** @return array<string, mixed> */
    private function rules(bool $partial = false): array
    {
        $sometimes = $partial ? ['sometimes'] : [];

        return [
            'handle' => [...$sometimes, 'string', 'max:30'],
            'name' => [...$sometimes, 'string', 'max:120'],
            'bio' => [...$sometimes, 'nullable', 'string', 'max:1000'],
            'logo_url' => [...$sometimes, 'nullable', 'url', 'max:500'],
            'cover_url' => [...$sometimes, 'nullable', 'url', 'max:500'],
            'event_types' => [...$sometimes, 'array'],
            'event_types.*' => [Rule::in(self::EVENT_TYPES)],
            'categories' => [...$sometimes, 'array'],
            'categories.*' => ['string', 'max:40'],
            'city' => [...$sometimes, 'nullable', 'string', 'max:80'],
            'base_lat' => [...$sometimes, 'nullable', 'numeric', 'between:-90,90'],
            'base_lng' => [...$sometimes, 'nullable', 'numeric', 'between:-180,180'],
            'service_radius_km' => [...$sometimes, 'integer', 'between:1,500'],
            'contact' => [...$sometimes, 'array:phone,whatsapp,email'],
            'policies' => ['sometimes', 'array:deposit_percent,cancellation,lead_time_hours,buffer_hours'],
            'policies.deposit_percent' => ['integer', 'between:0,100'],
            'policies.lead_time_hours' => ['integer', 'between:0,720'],
            'policies.buffer_hours' => ['integer', 'between:0,72'],
            'policies.cancellation' => ['array', 'max:5'],
            'policies.cancellation.*.days_before' => ['required', 'integer', 'between:0,365'],
            'policies.cancellation.*.refund_percent' => ['required', 'integer', 'between:0,100'],
            'delivery' => [...$sometimes, 'array:offered,base_fee_minor,per_km_minor,setup_offered,setup_fee_minor'],
            'delivery.offered' => ['boolean'],
            'delivery.base_fee_minor' => ['integer', 'min:0'],
            'delivery.per_km_minor' => ['integer', 'min:0'],
            'delivery.setup_offered' => ['boolean'],
            'delivery.setup_fee_minor' => ['integer', 'min:0'],
            'payout' => ['sometimes', 'array:method,account_ref,account_name'],
            'payout.method' => ['required_with:payout', Rule::in(['momo', 'bank'])],
            'payout.account_ref' => ['required_with:payout', 'string', 'max:40'],
        ];
    }
}
