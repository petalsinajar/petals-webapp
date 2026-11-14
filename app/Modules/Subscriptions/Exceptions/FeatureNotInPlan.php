<?php

namespace App\Modules\Subscriptions\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** 403 { message, code: "plan_feature", feature, upgrade_to } */
class FeatureNotInPlan extends HttpException
{
    public function __construct(public readonly string $feature, public readonly ?string $upgradeTo)
    {
        parent::__construct(403, 'This is not included in your plan.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'plan_feature',
            'feature' => $this->feature,
            'upgrade_to' => $this->upgradeTo,
        ], 403);
    }
}
