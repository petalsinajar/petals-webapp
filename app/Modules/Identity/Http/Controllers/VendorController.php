<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Category;
use App\Modules\Identity\Enums\VendorRole;
use App\Modules\Identity\Http\Resources\VendorResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Identity\Services\HandleRegistry;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaLibrary;
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

    public function __construct(
        private readonly HandleRegistry $handles,
        private readonly MediaLibrary $media,
    ) {}

    /**
     * GET /v1/vendors?lat=&lng=&category=&event_type=&q= — with lat/lng, vendors whose service
     * area covers that venue, nearest first; with only q, a name search, most followed first.
     * A category includes its children. One page of at most `limit`.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required_without:q', 'required_with:lng', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['required_without:q', 'required_with:lat', 'nullable', 'numeric', 'between:-180,180'],
            'q' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'string', 'max:40'],
            'event_type' => ['nullable', Rule::in(self::EVENT_TYPES)],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $near = isset($data['lat'], $data['lng']) ? [(float) $data['lat'], (float) $data['lng']] : null;

        $query = Vendor::query()
            ->where('status', 'active')
            ->when($data['category'] ?? null, fn ($q, $category) => $q->where(function ($any) use ($category) {
                foreach (Category::andChildren($category) as $id) {
                    $any->orWhereJsonContains('categories', $id);
                }
            }))
            ->when($data['event_type'] ?? null, fn ($q, $type) => $q->whereJsonContains('event_types', $type))
            ->when($data['q'] ?? null, function ($q, $term) {
                $like = '%'.$term.'%';
                $op = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $q->where(fn ($w) => $w->where('name', $op, $like)->orWhere('handle', $op, $like));
            });

        if ($near) {
            Geo::vendorsServing($query, ...$near);
            Geo::orderByDistance($query, ...$near);
        } else {
            $query->orderByDesc('followers_count');
        }

        $vendors = $query->orderBy('id')->limit($data['limit'] ?? 20)->get();
        $following = $this->followedAmong($request->user(), $vendors->pluck('id')->all());

        return response()->json([
            'data' => $vendors->map(fn (Vendor $vendor) => [
                ...(new VendorResource($vendor))->resolve(),
                'distance_km' => $near && $vendor->base_lat !== null && $vendor->base_lng !== null
                    ? round(Geo::distanceKm($vendor->base_lat, $vendor->base_lng, ...$near), 1)
                    : null,
                'is_following' => in_array($vendor->id, $following, true),
            ])->values(),
            'next_cursor' => null,
        ]);
    }

    /**
     * Which of these vendors the viewer follows.
     *
     * @param  list<string>  $vendorIds
     * @return list<string>
     */
    private function followedAmong(?User $viewer, array $vendorIds): array
    {
        if (! $viewer || $vendorIds === []) {
            return [];
        }

        return DB::table('follows')
            ->where(['follower_type' => Actor::USER, 'follower_id' => $viewer->id, 'target_type' => Actor::VENDOR])
            ->whereIn('target_id', $vendorIds)
            ->pluck('target_id')->all();
    }

    /** POST /v1/vendors — the signed-in user becomes the owner. */
    public function store(Request $request): VendorResource
    {
        $data = $request->validate([
            ...$this->rules(),
            'handle' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $images = $this->images($data, $request, null);

        $vendor = DB::transaction(function () use ($data, $request, $images) {
            $vendor = new Vendor(collect($data)->except(['handle', ...array_keys($images)])->all());
            $vendor->handle = HandleRegistry::normalize($data['handle']);
            $vendor->forceFill(array_map(fn ($m) => $m?->id, $images));
            $vendor->save();
            $this->media->attach(array_values($images), $vendor);
            $this->handles->claim($vendor->handle, $vendor);
            $vendor->members()->create(['user_id' => $request->user()->id, 'role' => VendorRole::Owner, 'status' => 'active']);

            return $vendor;
        });

        return new VendorResource($vendor->setAttribute('my_role', 'owner'));
    }

    /** GET /v1/vendors/{vendor} — by id or handle. Works signed out. */
    public function show(Request $request, string $vendor): VendorResource
    {
        $model = Vendor::where('id', $vendor)->orWhere('handle', HandleRegistry::normalize($vendor))->firstOrFail();
        $me = $request->user();

        $model->setAttribute('is_following', $this->followedAmong($me, [$model->id]) !== []);
        $model->setAttribute('my_role', $me?->roleIn($model)?->value);

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

        $images = $this->images($data, $request, $vendor);

        DB::transaction(function () use ($vendor, $data, $images) {
            $vendor->fill(collect($data)->except(array_keys($images))->all());
            $vendor->forceFill(array_map(fn ($m) => $m?->id, $images))->save();
            if ($images !== []) {
                $this->media->attach(array_values($images), $vendor);
                $this->media->releaseExcept($vendor, [$vendor->logo_media_id, $vendor->cover_media_id]);
                $vendor->unsetRelation('logo')->unsetRelation('cover');
            }
        });

        return new VendorResource($vendor->setAttribute('my_role', $request->user()->roleIn($vendor)?->value));
    }

    /**
     * Logo and cover media ids from the request, checked: the caller's own uploads, or ones
     * uploaded for this vendor by its team.
     *
     * @return array<string, Media|null> column => media (null clears it)
     */
    private function images(array $data, Request $request, ?Vendor $vendor): array
    {
        $images = [];
        foreach (['logo_media_id', 'cover_media_id'] as $column) {
            if (array_key_exists($column, $data)) {
                $images[$column] = $this->media->resolveOne($data[$column], $request->user(), $vendor, $column, for: $vendor);
            }
        }

        return $images;
    }

    /** @return array<string, mixed> */
    private function rules(bool $partial = false): array
    {
        $sometimes = $partial ? ['sometimes'] : [];

        return [
            'handle' => [...$sometimes, 'string', 'max:30'],
            'name' => [...$sometimes, 'string', 'max:120'],
            'bio' => [...$sometimes, 'nullable', 'string', 'max:1000'],
            // Ready images from POST /v1/uploads (purpose avatar / cover).
            'logo_media_id' => [...$sometimes, 'nullable', 'ulid'],
            'cover_media_id' => [...$sometimes, 'nullable', 'ulid'],
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
