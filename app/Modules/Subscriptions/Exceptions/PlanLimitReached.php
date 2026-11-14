<?php

namespace App\Modules\Subscriptions\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 403 { message, code: "plan_limit", limit, resource, used, upgrade_to } — the app shows an
 * upgrade prompt for the plan in upgrade_to (null when no plan allows more).
 */
class PlanLimitReached extends HttpException
{
    public function __construct(
        public readonly string $resource,
        public readonly int $limit,
        public readonly int $used,
        public readonly ?string $upgradeTo,
    ) {
        parent::__construct(403, "Your plan allows {$limit} {$resource}. Upgrade to add more.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'plan_limit',
            'resource' => $this->resource,
            'limit' => $this->limit,
            'used' => $this->used,
            'upgrade_to' => $this->upgradeTo,
        ], 403);
    }
}
