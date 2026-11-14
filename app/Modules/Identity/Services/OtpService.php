<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Identity\Models\OtpCode;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public function __construct(private readonly SmsSender $sms) {}

    public function send(string $phone): void
    {
        $length = config('eventapp.auth.otp_length');
        $review = $this->isReviewPhone($phone);
        $code = match (true) {
            $review => (string) config('eventapp.auth.review_code'),
            $this->usesDevCode() => config('eventapp.auth.otp_dev_code'),
            default => str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT),
        };

        // Only the newest code for a phone is valid.
        OtpCode::where('phone', $phone)->whereNull('consumed_at')->update(['consumed_at' => now()]);

        OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(config('eventapp.auth.otp_ttl_minutes')),
        ]);

        if (! $review && ! $this->usesDevCode()) {
            $this->sms->send($phone, "Your Petalsinajar code is {$code}. It expires in ".config('eventapp.auth.otp_ttl_minutes').' minutes.');
        }
    }

    /** @throws ValidationException */
    public function verify(string $phone, string $code): void
    {
        $otp = OtpCode::where('phone', $phone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (! $otp || $otp->attempts >= config('eventapp.auth.otp_max_attempts')) {
            throw ValidationException::withMessages(['code' => 'This code has expired. Request a new one.']);
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            throw ValidationException::withMessages(['code' => 'That code is not correct.']);
        }

        $otp->forceFill(['consumed_at' => now()])->save();
    }

    /** The app store review account: a fixed code, no SMS. Off unless both are set. */
    private function isReviewPhone(string $phone): bool
    {
        $reviewPhone = config('eventapp.auth.review_phone');
        $reviewCode = config('eventapp.auth.review_code');

        return filled($reviewPhone) && filled($reviewCode)
            && PhoneNumber::normalize((string) $reviewPhone) === $phone;
    }

    private function usesDevCode(): bool
    {
        return app()->environment(['local', 'testing']);
    }
}
