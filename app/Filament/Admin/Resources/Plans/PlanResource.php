<?php

namespace App\Filament\Admin\Resources\Plans;

use App\Filament\Admin\Resources\Plans\Pages\ManagePlans;
use App\Modules\Subscriptions\Models\Plan;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Vendor plans. Prices are in minor units (pesewas); an empty limit means unlimited.
 * Price changes apply to the next payment; existing bookings keep the commission they were made at.
 */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public const FEATURES = [
        'web_dashboard' => 'Web dashboard',
        'pinned_showcase_posts' => 'Pinned showcase posts',
        'branded_booking_link' => 'Branded booking link',
        'bulk_upload' => 'Bulk upload',
        'corporate_quotes' => 'Corporate quotes',
        'api_export' => 'API / export',
        'multiple_branches' => 'Multiple branches',
        'account_manager' => 'Account manager',
    ];

    public static function canCreate(): bool
    {
        return false; // plan keys are referenced in config (default and trial plan)
    }

    public static function form(Schema $schema): Schema
    {
        $levels = array_combine(config('eventapp.analytics.levels'), config('eventapp.analytics.levels'));
        $visibility = array_combine(array_keys(config('eventapp.visibility.boost')), array_keys(config('eventapp.visibility.boost')));

        return $schema->components([
            TextInput::make('name')->required()->maxLength(60),
            Toggle::make('active'),
            TextInput::make('price_monthly_minor')->label('Monthly price (minor units)')->integer()->minValue(0)->required(),
            TextInput::make('price_yearly_minor')->label('Yearly price (minor units)')->integer()->minValue(0)->required()
                ->helperText('Usually 10 × monthly.'),
            TextInput::make('commission_percent')->label('Commission on deposits (%)')->numeric()->minValue(0)->maxValue(50)->required(),
            TextInput::make('sort')->integer()->required()->helperText('Higher is a bigger plan: decides upgrade vs downgrade.'),
            Section::make('Limits (empty = unlimited)')->columns(3)->schema([
                TextInput::make('limits.items')->label('Items')->integer()->minValue(0),
                TextInput::make('limits.packages')->label('Packages')->integer()->minValue(0),
                TextInput::make('limits.team')->label('Team members')->integer()->minValue(1),
            ]),
            Section::make('Features')->columns(3)->schema([
                Select::make('features.analytics')->label('Analytics')->options($levels)->required(),
                Select::make('visibility_level')->options($visibility)->required(),
                TextInput::make('featured_weeks_per_month')->integer()->minValue(0)->maxValue(4)->required(),
                ...array_map(fn (string $key, string $label) => Toggle::make("features.{$key}")->label($label), array_keys(self::FEATURES), self::FEATURES),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $money = fn (int $state, Plan $record) => $record->currency.' '.number_format($state / 100, 2);

        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('key')->badge(),
                TextColumn::make('price_monthly_minor')->label('Monthly')->formatStateUsing($money),
                TextColumn::make('price_yearly_minor')->label('Yearly')->formatStateUsing($money),
                TextColumn::make('commission_percent')->label('Commission')->suffix('%'),
                TextColumn::make('visibility_level')->badge(),
                TextColumn::make('featured_weeks_per_month')->label('Featured weeks'),
                IconColumn::make('active')->boolean(),
            ])
            ->defaultSort('sort')
            ->recordActions([
                EditAction::make()->mutateDataUsing(function (array $data) {
                    // Blank limits mean unlimited (null), not zero.
                    $data['limits'] = array_map(fn ($v) => $v === null || $v === '' ? null : (int) $v, $data['limits'] ?? []);

                    return $data;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePlans::route('/')];
    }
}
