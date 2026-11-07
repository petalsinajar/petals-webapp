<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Availability\AvailabilityStore;
use App\Modules\Catalog\Availability\ReservationWindow;
use App\Modules\Catalog\Http\Resources\ItemResource;
use App\Modules\Catalog\Http\Resources\PackageResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Package;
use App\Support\Paginated;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Public catalog browsing for hosts and guests. */
class CatalogController extends Controller
{
    /** GET /v1/categories */
    public function categories(): JsonResponse
    {
        $categories = Category::orderBy('order')->get()->map(fn (Category $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'parent_id' => $c->parent_id,
            'icon' => $c->icon,
            'attributes' => $c->attributes_schema ?? [],
        ]);

        return response()->json(['data' => $categories]);
    }

    /** GET /v1/items?category=&event_type=&vendor_id=&q=&lat=&lng=&radius_km=&cursor= */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['nullable', 'string', 'max:40'],
            'event_type' => ['nullable', Rule::in(['wedding', 'birthday', 'corporate', 'other'])],
            'vendor_id' => ['nullable', 'string', 'max:26'],
            'q' => ['nullable', 'string', 'max:80'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius_km' => ['nullable', 'integer', 'between:1,200'],
            'cursor' => ['nullable', 'string', 'regex:/^[a-f0-9]{24}$/'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $limit = $data['limit'] ?? 20;

        $query = Item::where('status', 'active');

        if (! empty($data['category'])) {
            $query->where('category_id', $data['category']);
        }
        if (! empty($data['event_type'])) {
            $query->where('event_types', $data['event_type']);
        }
        if (! empty($data['vendor_id'])) {
            $query->where('vendor_id', $data['vendor_id']);
        }
        if (! empty($data['q'])) {
            $query->whereRaw(['$text' => ['$search' => $data['q']]]);
        }

        // Nearby search is ordered by distance, so it returns one page without a cursor.
        if (isset($data['lat'], $data['lng'])) {
            $items = $query->where('geo', 'near', [
                '$geometry' => ['type' => 'Point', 'coordinates' => [(float) $data['lng'], (float) $data['lat']]],
                '$maxDistance' => ($data['radius_km'] ?? 40) * 1000,
            ])->limit($limit)->get();

            return Paginated::manual($items, null, ItemResource::class);
        }

        if (! empty($data['cursor'])) {
            $query->where('_id', '<', $data['cursor']);
        }

        $items = $query->orderBy('_id', 'desc')->limit($limit + 1)->get();
        $next = $items->count() > $limit ? $items->slice($limit - 1, 1)->first()->id : null;

        return Paginated::manual($items->take($limit), $next, ItemResource::class);
    }

    /** GET /v1/items/{item} */
    public function show(string $item): ItemResource
    {
        return new ItemResource($this->findItem($item));
    }

    /** GET /v1/items/{item}/availability?variant_id=&from=2026-12-01&to=2026-12-31 */
    public function availability(Request $request, string $item, AvailabilityStore $store): JsonResponse
    {
        $model = $this->findItem($item);
        $data = $request->validate([
            'variant_id' => ['required', 'string'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $variant = $model->variant($data['variant_id']) ?? abort(404, 'Variant not found.');
        $from = CarbonImmutable::parse($data['from'], config('eventapp.business_timezone'));
        $to = CarbonImmutable::parse($data['to'], config('eventapp.business_timezone'))->addDay();

        if ($from->diffInDays($to) > 92) {
            throw ValidationException::withMessages(['to' => 'Ask for at most 92 days at a time.']);
        }

        $remaining = $store->remaining($data['variant_id'], (int) $variant['quantity'], ReservationWindow::days($from, $to));

        return response()->json([
            'item_id' => $model->id,
            'variant_id' => $data['variant_id'],
            'capacity' => (int) $variant['quantity'],
            'days' => collect($remaining)->map(fn (int $left, string $day) => ['day' => $day, 'available' => $left])->values(),
        ]);
    }

    /** GET /v1/vendors/{vendor}/packages and GET /v1/packages/{package} */
    public function vendorPackages(string $vendor): JsonResponse
    {
        $packages = Package::where('vendor_id', $vendor)->where('status', 'active')->orderBy('price_minor')->get();

        return response()->json(['data' => PackageResource::collection($packages)->resolve()]);
    }

    public function package(string $package): PackageResource
    {
        $model = preg_match('/^[a-f0-9]{24}$/', $package) ? Package::find($package) : null;
        abort_if(! $model || $model->status !== 'active', 404);

        return new PackageResource($model);
    }

    private function findItem(string $id): Item
    {
        $item = preg_match('/^[a-f0-9]{24}$/', $id) ? Item::find($id) : null;
        abort_if(! $item || $item->status === 'archived', 404, 'Item not found.');

        return $item;
    }
}
