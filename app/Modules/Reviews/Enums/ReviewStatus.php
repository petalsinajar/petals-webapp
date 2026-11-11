<?php

namespace App\Modules\Reviews\Enums;

enum ReviewStatus: string
{
    case Pending = 'pending';     // submitted, hidden until both sides have reviewed (or the blind window ends)
    case Published = 'published';
    case Removed = 'removed';     // broke the content rules; kept with the reason
}
