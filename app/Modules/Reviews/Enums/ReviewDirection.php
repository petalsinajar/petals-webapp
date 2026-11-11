<?php

namespace App\Modules\Reviews\Enums;

enum ReviewDirection: string
{
    case CustomerToVendor = 'customer_to_vendor'; // public, on the vendor's profile
    case VendorToCustomer = 'vendor_to_customer'; // private, for vendor teams the customer books

    public function other(): self
    {
        return $this === self::CustomerToVendor ? self::VendorToCustomer : self::CustomerToVendor;
    }

    /** @return list<string> */
    public function aspects(): array
    {
        return config("eventapp.reviews.aspects.{$this->value}");
    }
}
