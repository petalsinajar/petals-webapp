<?php

namespace Tests\Unit;

use App\Modules\Reviews\Enums\ReviewDirection;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\Review;
use Tests\TestCase;

class ReviewRulesTest extends TestCase
{
    public function test_each_direction_has_its_own_aspects_and_an_opposite(): void
    {
        $this->assertSame(ReviewDirection::VendorToCustomer, ReviewDirection::CustomerToVendor->other());
        $this->assertSame(ReviewDirection::CustomerToVendor, ReviewDirection::VendorToCustomer->other());
        $this->assertSame(['quality', 'punctuality', 'setup', 'communication'], ReviewDirection::CustomerToVendor->aspects());
        $this->assertSame(['communication', 'care_of_items', 'on_time_return'], ReviewDirection::VendorToCustomer->aspects());
    }

    public function test_a_review_is_editable_only_while_pending_and_inside_its_window(): void
    {
        $review = new Review(['status' => ReviewStatus::Pending, 'edited_until' => now()->addHour()]);
        $this->assertTrue($review->isEditable());

        $review->edited_until = now()->subSecond();
        $this->assertFalse($review->isEditable());

        $review->forceFill(['edited_until' => now()->addHour(), 'status' => ReviewStatus::Published]);
        $this->assertFalse($review->isEditable());
    }

    public function test_the_vendor_reply_is_editable_for_48_hours_after_it_is_first_posted(): void
    {
        $review = new Review;
        $this->assertTrue($review->replyEditable(), 'no reply yet');

        $review->vendor_replied_at = now()->subHours(47);
        $this->assertTrue($review->replyEditable());

        $review->vendor_replied_at = now()->subHours(49);
        $this->assertFalse($review->replyEditable());
    }
}
