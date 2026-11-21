<?php

namespace App\Filament\Admin\Resources\CampaignSegments;

use App\Filament\Admin\Resources\CampaignSegments\Pages\ManageCampaignSegments;
use App\Modules\Campaigns\Models\CampaignSegment;
use App\Modules\Campaigns\Services\AudienceCsv;
use App\Modules\Campaigns\Services\SegmentQuery;
use App\Modules\Events\Models\EventType;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Subscriptions\Models\Plan;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

/**
 * Saved audiences for campaigns. Every filter is optional; the count updates as they
 * change. Opt-outs and unreachable people are left out per channel when a campaign runs.
 */
class CampaignSegmentResource extends Resource
{
    protected static ?string $model = CampaignSegment::class;

    protected static ?string $modelLabel = 'audience';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Campaigns';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasAdminPermission('campaigns');
    }

    public static function form(Schema $schema): Schema
    {
        $live = fn ($field) => $field->live(onBlur: true);

        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(120)->columnSpanFull(),
            Text::make(fn (Get $get) => 'Audience now: '.number_format(SegmentQuery::count(self::clean($get('filters') ?? []))).' people')
                ->weight(FontWeight::Bold)->columnSpanFull(),

            Section::make('Who')->columns(2)->schema([
                CheckboxList::make('filters.roles')->label('Role (any of)')->live()
                    ->options(['host' => 'Hosts', 'guest' => 'Guests', 'vendor' => 'Vendors']),
                CheckboxList::make('filters.vendor_plans')->label('Vendor plan')->live()
                    ->options(fn () => Plan::orderBy('sort')->pluck('name', 'key')->all())
                    ->visible(fn (Get $get) => in_array('vendor', $get('filters.roles') ?? [], true)),
                CheckboxList::make('filters.event_types')->label('Interested in (hosted or joined)')->live()
                    ->options(fn () => EventType::options()),
                CheckboxList::make('filters.platforms')->label('App platform')->live()
                    ->options(['android' => 'Android', 'ios' => 'iPhone']),
            ]),

            Section::make('Where')->columns(4)->schema([
                $live(TextInput::make('filters.city')->label('City')->maxLength(80)),
                $live(TextInput::make('filters.near.lat')->label('Within … of lat')->numeric()->minValue(-90)->maxValue(90)),
                $live(TextInput::make('filters.near.lng')->label('lng')->numeric()->minValue(-180)->maxValue(180)),
                $live(TextInput::make('filters.near.km')->label('km')->numeric()->minValue(1)->maxValue(500)),
            ]),

            Section::make('Activity')->columns(2)->schema([
                $live(DatePicker::make('filters.signed_up_from')->label('Signed up from')),
                $live(DatePicker::make('filters.signed_up_to')->label('Signed up until')),
                $live(DatePicker::make('filters.last_active_after')->label('Last active after')),
                $live(DatePicker::make('filters.last_active_before')->label('Last active before')),
                Select::make('filters.has_booked')->label('Bookings')->live()
                    ->options(['1' => 'Has booked', '0' => 'Has never booked'])->placeholder('Any'),
                Select::make('filters.follows_vendor_id')->label('Follows vendor')->live()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Vendor::where('name', 'like', "%{$search}%")->limit(20)->pluck('name', 'id')->all())
                    ->getOptionLabelUsing(fn ($value) => Vendor::find($value)?->name),
                Toggle::make('filters.upcoming_event.enabled')->label('Has an upcoming event')->live()->columnSpanFull(),
                CheckboxList::make('filters.upcoming_event.types')->label('Event type')->live()
                    ->options(fn () => EventType::options())
                    ->visible(fn (Get $get) => (bool) $get('filters.upcoming_event.enabled')),
                $live(DatePicker::make('filters.upcoming_event.from')->label('Starting from'))
                    ->visible(fn (Get $get) => (bool) $get('filters.upcoming_event.enabled')),
                $live(DatePicker::make('filters.upcoming_event.to')->label('Starting until'))
                    ->visible(fn (Get $get) => (bool) $get('filters.upcoming_event.enabled')),
            ]),

            Section::make('One-off list')->description('A CSV of user ids or phone numbers. Only existing users are kept; the other filters still apply.')->schema([
                Hidden::make('filters.user_ids'),
                FileUpload::make('csv')->label('Upload CSV')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                    ->storeFiles(false)->dehydrated(false)->live()
                    ->afterStateUpdated(function ($state, Set $set) {
                        $file = is_array($state) ? reset($state) : $state;
                        if ($file instanceof TemporaryUploadedFile) {
                            $set('filters.user_ids', AudienceCsv::match($file->get())['user_ids']);
                        }
                    }),
                Text::make(fn (Get $get) => is_array($get('filters.user_ids'))
                    ? number_format(count($get('filters.user_ids'))).' users from the list.'
                    : 'No list: everyone matching the filters.'),
                Toggle::make('clear_list')->label('Remove the list')->dehydrated(false)->live()
                    ->visible(fn (Get $get) => is_array($get('filters.user_ids')))
                    ->afterStateUpdated(fn ($state, Set $set) => $state ? $set('filters.user_ids', null) : null),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('people')->label('People now')
                    ->state(fn (CampaignSegment $record) => number_format(SegmentQuery::count($record->filters ?? []))),
                TextColumn::make('filters')->label('Filters')->limit(80)
                    ->state(fn (CampaignSegment $record) => self::describe($record->filters ?? [])),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data) => self::withCleanFilters($data)),
                DeleteAction::make(),
            ]);
    }

    /** @param array<string, mixed> $data */
    public static function withCleanFilters(array $data): array
    {
        $data['filters'] = self::clean($data['filters'] ?? []);
        unset($data['csv'], $data['clear_list']);

        return $data;
    }

    /**
     * Form state to SegmentQuery filters: empty values dropped, half-filled "near" ignored,
     * the upcoming-event switch folded in.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function clean(array $filters): array
    {
        $upcoming = $filters['upcoming_event'] ?? [];
        unset($filters['upcoming_event']);
        if (! empty($upcoming['enabled'])) {
            unset($upcoming['enabled']);
            $filters['upcoming_event'] = array_filter($upcoming, fn ($v) => filled($v));
        }

        $near = $filters['near'] ?? [];
        unset($filters['near']);
        if (filled($near['lat'] ?? null) && filled($near['lng'] ?? null) && filled($near['km'] ?? null)) {
            $filters['near'] = ['lat' => (float) $near['lat'], 'lng' => (float) $near['lng'], 'km' => (float) $near['km']];
        }

        if (isset($filters['has_booked']) && $filters['has_booked'] !== '' && $filters['has_booked'] !== null) {
            $filters['has_booked'] = (bool) (int) $filters['has_booked'];
        }
        if (! in_array('vendor', $filters['roles'] ?? [], true)) {
            unset($filters['vendor_plans']);
        }

        return array_filter($filters, fn ($v, $k) => $k === 'user_ids' ? is_array($v) : ($v !== [] && $v !== null && $v !== ''), ARRAY_FILTER_USE_BOTH);
    }

    /** A one-line summary for the table. */
    public static function describe(array $filters): string
    {
        return collect($filters)->map(fn ($v, $k) => match ($k) {
            'user_ids' => count($v).' listed users',
            'near' => "within {$v['km']} km",
            'upcoming_event' => 'upcoming event',
            default => str_replace('_', ' ', $k).': '.(is_array($v) ? implode(', ', $v) : (is_bool($v) ? ($v ? 'yes' : 'no') : $v)),
        })->implode(' · ') ?: 'Everyone';
    }

    public static function getPages(): array
    {
        return ['index' => ManageCampaignSegments::route('/')];
    }
}
