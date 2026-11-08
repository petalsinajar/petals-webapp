<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The app-wide list shape: { "data": [...], "next_cursor": "..." | null }.
 */
final class Paginated
{
    /**
     * @param  class-string<JsonResource>  $resource
     */
    public static function response(CursorPaginator $paginator, string $resource): JsonResponse
    {
        return response()->json([
            'data' => $resource::collection($paginator->items())->resolve(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
        ]);
    }

    /**
     * For single-page lists that are not cursor paginated (e.g. nearest-first searches).
     *
     * @param  iterable<mixed>  $items
     * @param  class-string<JsonResource>  $resource
     */
    public static function manual(iterable $items, ?string $nextCursor, string $resource): JsonResponse
    {
        return response()->json([
            'data' => $resource::collection(collect($items))->resolve(),
            'next_cursor' => $nextCursor,
        ]);
    }
}
