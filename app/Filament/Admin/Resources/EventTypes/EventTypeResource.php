<?php

namespace App\Filament\Admin\Resources\EventTypes;

use App\Filament\Admin\Resources\EventTypes\Pages\ManageEventTypes;
use App\Modules\Catalog\Models\Category;
use App\Modules\Events\Models\EventType;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The kinds of events hosts can create (wedding, religious, …). The slug is stored on
 * events, items, vendors and posts, so it's set once and can't change. There's no delete:
 * turn a type off instead, and its existing events keep showing it.
 */
class EventTypeResource extends Resource
{
    protected static ?string $model = EventType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('slug')
                ->required()->maxLength(30)
                ->regex('/^[a-z][a-z0-9_]*$/')
                ->helperText('Lowercase, e.g. "religious". Saved on every event; it can\'t be changed later.')
                ->unique(ignoreRecord: true)
                ->disabledOn('edit'),
            TextInput::make('name')->required()->maxLength(40)->placeholder('Religious'),
            TextInput::make('plural')->required()->maxLength(60)->placeholder('Religious events')
                ->helperText('Shown on filters, e.g. "Weddings".'),
            TextInput::make('description')->maxLength(160)
                ->placeholder('Church programs, crusades, thanksgiving services'),
            Select::make('icon')->required()->default('celebration')->options(EventType::ICONS),
            ColorPicker::make('color')->helperText('The type\'s accent in the app.'),
            Select::make('required_categories')->label('Usually rents')->multiple()
                ->options(fn () => Category::orderBy('sort')->pluck('name', 'id')->all())
                ->helperText('Hosts get vendor suggestions for these, in this order.'),
            TextInput::make('sort')->integer()->default(100)->required(),
            Toggle::make('active')->default(true)
                ->helperText('Off: no new events of this type; existing ones keep it.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ColorColumn::make('color'),
                TextColumn::make('name')->searchable(),
                TextColumn::make('slug')->badge(),
                TextColumn::make('description')->limit(50),
                IconColumn::make('active')->boolean(),
                TextColumn::make('sort')->sortable(),
            ])
            ->defaultSort('sort')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageEventTypes::route('/')];
    }
}
