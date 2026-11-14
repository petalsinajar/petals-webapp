<?php

namespace App\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * A kind of event (wedding, religious, …), managed in the admin panel. The slug is what
 * events, items, vendors and posts store, so it never changes; retire a type by making it
 * inactive (it stays valid for existing data but isn't offered for new events).
 *
 * @property string $slug
 * @property string $name
 * @property bool $active
 * @property list<string>|null $required_categories
 */
class EventType extends Model
{
    /** Icons the app knows how to draw. */
    public const ICONS = [
        'favorite' => 'Heart (weddings)',
        'cake' => 'Cake (birthdays)',
        'business' => 'Briefcase (corporate)',
        'church' => 'Church',
        'mosque' => 'Mosque',
        'celebration' => 'Party popper',
        'school' => 'School',
        'graduation' => 'Graduation cap',
        'music' => 'Music',
        'sports' => 'Sports',
        'family' => 'Family',
        'funeral' => 'Memorial',
    ];

    private const CACHE_KEY = 'event_types.all';

    protected $primaryKey = 'slug';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['slug', 'name', 'plural', 'description', 'icon', 'color', 'required_categories', 'sort', 'active'];

    protected function casts(): array
    {
        return [
            'required_categories' => 'array',
            'active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $forget = fn () => Cache::forget(self::CACHE_KEY);
        static::saved($forget);
        static::deleted($forget);
    }

    /** @return list<array<string, mixed>> every type, in order, cached until one changes */
    public static function catalog(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => self::query()
            ->orderBy('sort')->orderBy('name')->get()
            ->map(fn (self $t) => $t->toPayload())->all());
    }

    /** @return list<string> */
    public static function slugs(bool $activeOnly = false): array
    {
        return array_values(array_map(
            fn (array $t) => $t['slug'],
            array_filter(self::catalog(), fn (array $t) => ! $activeOnly || $t['active']),
        ));
    }

    /** Valid for filters and existing data: every known type. */
    public static function rule(): In
    {
        return Rule::in(self::slugs());
    }

    /** Valid for something new (an event, an item's tags): only types still offered. */
    public static function activeRule(): In
    {
        return Rule::in(self::slugs(activeOnly: true));
    }

    /** @return array<string, string> slug => name, for admin dropdowns */
    public static function options(bool $activeOnly = false): array
    {
        return collect(self::catalog())
            ->filter(fn (array $t) => ! $activeOnly || $t['active'])
            ->mapWithKeys(fn (array $t) => [$t['slug'] => $t['name']])->all();
    }

    /** @return array<string, list<string>> slug => categories it usually rents (types that set some) */
    public static function requiredCategories(): array
    {
        return collect(self::catalog())
            ->filter(fn (array $t) => $t['required_categories'] !== [])
            ->mapWithKeys(fn (array $t) => [$t['slug'] => $t['required_categories']])->all();
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'plural' => $this->plural,
            'description' => $this->description,
            'icon' => $this->icon,
            'color' => $this->color,
            'required_categories' => $this->required_categories ?? [],
            'active' => $this->active,
        ];
    }
}
