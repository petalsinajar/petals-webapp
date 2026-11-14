<?php

namespace App\Modules\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Events\Models\EventType;
use Illuminate\Http\JsonResponse;

class EventTypeController extends Controller
{
    /**
     * GET /v1/event-types — every type, in order. Inactive ones are included (marked) so
     * older events still show their name; the app offers only active ones for new events.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => EventType::catalog()]);
    }
}
