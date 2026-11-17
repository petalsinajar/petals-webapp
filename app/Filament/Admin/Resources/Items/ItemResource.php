<?php

namespace App\Filament\Admin\Resources\Items;

use App\Filament\Admin\Resources\Items\Pages\ManageItems;
use App\Filament\Vendor\Resources\Items\ItemResource as VendorItemResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every vendor's inventory, so the team can review listings and add items for vendors while
 * they get started. Same form as the vendor dashboard plus a vendor picker. Plan item limits
 * don't apply here: what the team adds stays live, as after a downgrade.
 */
class ItemResource extends Resource
{
    protected static ?string $model = Item::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $navigationLabel = 'Inventory';

    protected static ?string $recordTitleAttribute = 'title';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['vendor', 'variants']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Vendor')->schema([
                // Fixed once created: bookings and stock counters belong to the vendor.
                Select::make('vendor_id')->label('Vendor')->required()
                    ->relationship('vendor', 'name')->searchable()->preload()
                    ->disabledOn('edit'),
            ]),
            ...VendorItemResource::formComponents(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('vendor.name')->label('Vendor')->searchable()->sortable(),
                TextColumn::make('category_id')->label('Category')->badge(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'active' => 'success', 'paused' => 'warning', 'archived' => 'gray', default => 'info',
                }),
                TextColumn::make('stock')->label('Stock')
                    ->state(fn (Item $record) => $record->variants->sum('quantity').' units, '.$record->variants->count().' variants'),
                TextColumn::make('bookings_count')->label('Bookings')->numeric()->sortable()->toggleable(),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('vendor')->relationship('vendor', 'name')->searchable()->preload(),
                SelectFilter::make('status')->options(['draft' => 'Draft', 'active' => 'Active', 'paused' => 'Paused', 'archived' => 'Archived']),
                SelectFilter::make('category_id')->label('Category')->options(fn () => Category::orderBy('sort')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                EditAction::make()->after(fn (Item $record) => VendorItemResource::warnAboutCapacity($record)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageItems::route('/')];
    }
}
