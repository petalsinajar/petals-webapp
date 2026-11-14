<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Availability\AvailabilityStore;
use App\Modules\Catalog\Availability\ReservationWindow;
use App\Modules\Catalog\Http\Resources\ItemResource;
use App\Modules\Catalog\Http\Resources\PackageResource;
use App\Modules\Catalog\Models\Blackout;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Package;
use App\Modules\Events\Models\EventType;
use App\Support\Geo;
use App\Support\Paginated;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Public catalog browsing for hosts and guests. */
class CatalogController extends Controller
{
    /** GET /v1/categories */
    public function categories(): JsonResponse
    {
        $categories = Category::orderBy('sort')->get()->map(fn (Category $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'parent_id' => $c->parent_id,
            'icon' => $c->icon,
            'attributes' => $c->attributes_schema ?? [],
        ]);

        return response()->json(['data' => $categories]);
    }

    /**
     * GET /v1/items?category=&event_type=&vendor_id=&q=&lat=&lng=&cursor=
     *
     * A category includes its children. With lat/lng: only items from vendors whose service
     * area covers that point, nearest vendor first; the cursor is then an offset ("o:40").
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['nullable', 'string', 'max:40'],
            'event_type' => ['nullable', EventType::rule()],
            'vendor_id' => ['nullable', 'ulid'],
            'q' => ['nullable', 'string', 'max:80'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $limit = $data['limit'] ?? 20;

        $query = Item::query()->select('items.*')->with(['variants', 'vendor', 'media', 'vendor.logo'])
            ->where('items.status', 'active')
            ->when($data['category'] ?? null, fn ($q, $category) => $q->whereIn('items.category_id', Category::andChildren($category)))
            ->when($data['event_type'] ?? null, fn ($q, $type) => $q->whereJsonContains('items.event_types', $type))
            ->when($data['vendor_id'] ?? null, fn ($q, $vendor) => $q->where('items.vendor_id', strtolower($vendor)))
            ->when($data['q'] ?? null, function ($q, $term) {
                $like = '%'.$term.'%';
                $op = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $q->where(fn ($w) => $w->where('items.title', $op, $like)->orWhere('items.description', $op, $like));
            });

        if (isset($data['lat'], $data['lng'])) {
            $query->join('vendors', 'vendors.id', '=', 'items.vendor_id')->where('vendors.status', 'active');
            Geo::vendorsServing($query, (float) $data['lat'], (float) $data['lng']);
            Geo::orderByDistance($query, (float) $data['lat'], (float) $data['lng']);

            $offset = preg_match('/^o:(\d{1,5})$/', (string) $request->query('cursor'), $m) ? (int) $m[1] : 0;
            // One extra row tells us whether there's another page.
            $rows = $query->orderByDesc('items.id')->offset($offset)->limit($limit + 1)->get();
            $next = $rows->count() > $limit ? 'o:'.($offset + $limit) : null;

            return Paginated::manual($rows->take($limit), $next, ItemResource::class);
        }

        return Paginated::response($query->orderByDesc('items.id')->cursorPaginate($limit), ItemResource::class);
    }

    /** GET /v1/items/{item} */
    public function show(string $item): ItemResource
    {
        return new ItemResource($this->findItem($item)->load(['variants', 'vendor', 'media', 'vendor.logo']));
    }

    /** GET /v1/items/{item}/availability?variant_id=&from=2026-12-01&to=2026-12-31 */
    public function availability(Request $request, string $item, AvailabilityStore $store): JsonResponse
    {
        $model = $this->findItem($item);
        $data = $request->validate([
            'variant_id' => ['required', 'ulid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $variant = $model->variants()->find(strtolower($data['variant_id'])) ?? abort(404, 'Variant not found.');
        $from = CarbonImmutable::parse($data['from'], config('eventapp.business_timezone'));
        $to = CarbonImmutable::parse($data['to'], config('eventapp.business_timezone'))->addDay();

        if ($from->diffInDays($to) > 92) {
            throw ValidationException::withMessages(['to' => 'Ask for at most 92 days at a time.']);
        }

        $remaining = $store->remaining($variant->id, $variant->quantity, ReservationWindow::days($from, $to));
        // The vendor takes no bookings on these days, whatever is in stock.
        $closed = array_flip(Blackout::blockedDays($model->vendor_id, array_keys($remaining)));

        return response()->json([
            'item_id' => $model->id,
            'variant_id' => $variant->id,
            'capacity' => $variant->quantity,
            'days' => collect($remaining)->map(fn (int $left, string $day) => [
                'day' => $day,
                'available' => isset($closed[$day]) ? 0 : $left,
                'closed' => isset($closed[$day]),
            ])->values(),
        ]);
    }

    /** GET /v1/vendors/{vendor}/packages and GET /v1/packages/{package} */
    public function vendorPackages(string $vendor): JsonResponse
    {
        $packages = Package::with('lines', 'media')->where('vendor_id', $vendor)->where('status', 'active')->orderBy('price_minor')->get();

        return response()->json(['data' => PackageResource::collection($packages)->resolve()]);
    }

    /**
     * GET /v1/packages?lat=&lng=&event_type= — packages from vendors serving that point,
     * nearest vendor first. One page of at most `limit`.
     */
    public function packages(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'event_type' => ['nullable', EventType::rule()],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $query = Package::query()->select('packages.*')->with(['lines', 'vendor', 'media', 'vendor.logo'])
            ->join('vendors', 'vendors.id', '=', 'packages.vendor_id')
            ->where('packages.status', 'active')->where('vendors.status', 'active')
            ->when($data['event_type'] ?? null, fn ($q, $type) => $q->whereJsonContains('packages.event_types', $type));
        Geo::vendorsServing($query, (float) $data['lat'], (float) $data['lng']);
        Geo::orderByDistance($query, (float) $data['lat'], (float) $data['lng']);

        $packages = $query->orderBy('packages.price_minor')->limit($data['limit'] ?? 20)->get();

        return Paginated::manual($packages, null, PackageResource::class);
    }

    public function package(string $package): PackageResource
    {
        $model = Str::isUlid($package) ? Package::with(['lines', 'vendor', 'media', 'vendor.logo'])->find(strtolower($package)) : null;
        abort_if(! $model || $model->status !== 'active', 404);

        return new PackageResource($model);
    }

    private function findItem(string $id): Item
    {
        $item = Str::isUlid($id) ? Item::find(strtolower($id)) : null;
        abort_if(! $item || $item->status === 'archived', 404, 'Item not found.');

        return $item;
    }
}
