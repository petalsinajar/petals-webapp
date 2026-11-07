<?php

namespace App\Modules\Recommendations\Enums;

/** Why a vendor was suggested, which is also the stage of planning the event is in. */
enum RecommendationReason: string
{
    case Checklist = 'checklist';               // just after the event was created
    case MissingCategory = 'missing_category';  // 8 to 2 weeks out, something isn't booked yet
    case LastCall = 'last_call';                // about 10 days out
}
