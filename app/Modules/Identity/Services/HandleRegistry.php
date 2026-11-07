<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Users and vendors share one @handle namespace, enforced by the handles table's primary key.
 */
class HandleRegistry
{
    public const PATTERN = '/^[a-z0-9](?:[a-z0-9._]{1,28}[a-z0-9])$/';

    private const RESERVED = [
        'admin', 'vendor', 'support', 'help', 'api', 'events', 'event', 'app', 'settings', 'me',
        'login', 'signup', 'about', 'terms', 'privacy', 'explore', 'discover',
    ];

    public static function normalize(string $handle): string
    {
        return Str::lower(ltrim(trim($handle), '@'));
    }

    public function isValid(string $handle): bool
    {
        return (bool) preg_match(self::PATTERN, $handle)
            && ! str_contains($handle, '..')
            && ! in_array($handle, self::RESERVED, true);
    }

    public function isAvailable(string $handle): bool
    {
        $handle = self::normalize($handle);

        return $this->isValid($handle) && ! DB::table('handles')->where('handle', $handle)->exists();
    }

    /**
     * Claim a handle for an owner. Call inside the same transaction that creates or updates the owner.
     *
     * @throws ValidationException when the handle is taken or invalid.
     */
    public function claim(string $handle, User|Vendor $owner): string
    {
        $handle = self::normalize($handle);

        if (! $this->isValid($handle)) {
            throw ValidationException::withMessages(['handle' => 'Use 3–30 letters, numbers, dots or underscores.']);
        }

        $ownerType = $owner instanceof User ? 'user' : 'vendor';

        try {
            DB::table('handles')->insert([
                'handle' => $handle,
                'owner_type' => $ownerType,
                'owner_id' => $owner->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            throw ValidationException::withMessages(['handle' => 'That handle is already taken.']);
        }

        return $handle;
    }

    public function release(string $handle): void
    {
        DB::table('handles')->where('handle', self::normalize($handle))->delete();
    }

    /** Change an owner's handle atomically. */
    public function rename(User|Vendor $owner, string $newHandle): string
    {
        return DB::transaction(function () use ($owner, $newHandle) {
            $handle = $this->claim($newHandle, $owner);
            $this->release($owner->handle);
            $owner->forceFill(['handle' => $handle])->save();

            return $handle;
        });
    }

    /** @return array{type: string, id: string}|null */
    public function resolve(string $handle): ?array
    {
        $row = DB::table('handles')->where('handle', self::normalize($handle))->first();

        return $row ? ['type' => $row->owner_type, 'id' => $row->owner_id] : null;
    }

    /** Suggest a free handle from a display name, e.g. "Ama Mensah" -> "ama.mensah2". */
    public function suggest(string $name): string
    {
        $base = Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', '.')->trim('.')->limit(24, '')->toString();
        $base = strlen($base) >= 3 ? $base : 'user'.$base;

        $candidate = $base;
        $i = 1;
        while (! $this->isAvailable($candidate)) {
            $candidate = $base.$i++;
        }

        return $candidate;
    }
}
