<?php

namespace App\Filament\Vendor\Resources\Items;

use App\Filament\Vendor\Resources\Items\Pages\ManageItems;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Services\CapacityWarnings;
use App\Modules\Events\Models\EventType;
use App\Modules\Subscriptions\Services\Entitlements;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Bulk inventory editing for vendors, scoped to the current vendor through Item::vendor()
 * and Vendor::items(). Variants are saved through the relationship, so ItemVariant's hooks
 * apply here exactly as in the API: quantity changes reach the day counters, and removing a
 * variant that bookings point at deactivates it instead.
 *
 * Photos aren't edited here: they upload straight from the app to storage (POST /v1/uploads),
 * which a dashboard form can't do without passing the file through this server.
 */
class ItemResource extends Resource
{
    protected static ?string $model = Item::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $navigationLabel = 'Inventory';

    protected static ?string $recordTitleAttribute = 'title';

    /** Over the plan's item limit, existing items stay editable but no new ones. */
    public static function canCreate(): bool
    {
        $vendor = Filament::getTenant();

        return parent::canCreate() && (! $vendor || app(Entitlements::class)->canAddItem($vendor));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Item')->columns(2)->schema([
                TextInput::make('title')->required()->maxLength(120),
                Select::make('category_id')->label('Category')->required()
                    ->options(fn () => Category::orderBy('sort')->pluck('name', 'id')->all()),
                Textarea::make('description')->columnSpanFull(),
                Select::make('event_types')->multiple()
                    ->options(fn () => EventType::options()),
                Select::make('status')->required()->default('draft')
                    ->options(['draft' => 'Draft', 'active' => 'Active', 'paused' => 'Paused', 'archived' => 'Archived']),
            ]),

            Section::make('Pricing')->columns(3)->schema([
                Select::make('pricing_unit')->label('Charged')->required()->default('per_event')
                    ->options(['per_event' => 'Per event', 'per_day' => 'Per day', 'per_hour' => 'Per hour']),
                TextInput::make('currency')->default(config('eventapp.currency'))->length(3)->required(),
                TextInput::make('min_qty')->integer()->minValue(1)->default(1)->required(),
                Repeater::make('price_tiers')->label('Price tiers (minor units)')->columnSpanFull()->columns(2)
                    ->defaultItems(1)->minItems(1)
                    ->schema([
                        TextInput::make('min_qty')->label('From quantity')->integer()->minValue(1)->default(1)->required(),
                        TextInput::make('price_minor')->label('Unit price')->integer()->minValue(0)->required(),
                    ]),
                TextInput::make('damage_deposit_minor')->label('Damage deposit per unit')->integer()->minValue(0)->default(0),
                Toggle::make('instant_book')->label('Instant book (no approval needed)'),
                Toggle::make('requires_setup'),
            ]),

            Section::make('Stock')->schema([
                Repeater::make('variants')->relationship()->orderColumn('sort')
                    ->columns(4)->defaultItems(1)->minItems(1)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(60),
                        TextInput::make('sku')->maxLength(40),
                        TextInput::make('quantity')->integer()->minValue(0)->required(),
                        TextInput::make('price_delta_minor')->label('Price difference')->integer()->default(0),
                        Toggle::make('active')->default(true),
                    ]),
            ]),

        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('category_id')->label('Category')->badge(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'active' => 'success', 'paused' => 'warning', 'archived' => 'gray', default => 'info',
                }),
                TextColumn::make('stock')->label('Stock')
                    ->state(fn (Item $record) => $record->variants->sum('quantity').' units, '.$record->variants->count().' variants'),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['draft' => 'Draft', 'active' => 'Active', 'paused' => 'Paused', 'archived' => 'Archived']),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('variants'))
            ->recordActions([
                EditAction::make()->after(function (Item $record) {
                    $warnings = app(CapacityWarnings::class)->pull($record->id);
                    if ($warnings !== []) {
                        $days = collect($warnings)->flatMap(fn (array $w) => $w['days'])->unique()->sort()->implode(', ');
                        Notification::make()->warning()->persistent()
                            ->title('More units are already booked than you now have')
                            ->body("Check these days: {$days}.")
                            ->send();
                    }
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageItems::route('/')];
    }
}
