<?php

namespace App\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Who is acting socially: a person or a vendor business.
 * Serialised as { "type": "user" | "vendor", "id": "<ulid>" }.
 */
final class Actor
{
    public const USER = 'user';

    public const VENDOR = 'vendor';

    public function __construct(
        public readonly string $type,
        public readonly string $id,
    ) {
        if (! in_array($type, [self::USER, self::VENDOR], true)) {
            throw new InvalidArgumentException("Unknown actor type [{$type}].");
        }
    }

    public static function of(Model $model): self
    {
        return match (true) {
            $model instanceof User => new self(self::USER, $model->getKey()),
            $model instanceof Vendor => new self(self::VENDOR, $model->getKey()),
            default => throw new InvalidArgumentException('Model is not an actor.'),
        };
    }

    public function resolve(): User|Vendor|null
    {
        return $this->type === self::USER
            ? User::find($this->id)
            : Vendor::find($this->id);
    }

    public function is(Model $model): bool
    {
        $other = self::of($model);

        return $other->type === $this->type && $other->id === $this->id;
    }

    /** @return array{type: string, id: string} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id];
    }
}
