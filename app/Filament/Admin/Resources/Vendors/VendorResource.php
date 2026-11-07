<?php

namespace App\Filament\Admin\Resources\Vendors;

use App\Filament\Admin\Resources\Vendors\Pages\ManageVendors;
use App\Modules\Identity\Models\Vendor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class VendorResource extends Resource
{
    protected static ?string $model = Vendor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120),
            TextInput::make('city')->maxLength(80),
            Textarea::make('bio')->columnSpanFull(),
            Select::make('verification_status')
                ->options(['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected'])
                ->required(),
            Select::make('status')->options(['active' => 'Active', 'suspended' => 'Suspended'])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('handle')->prefix('@')->searchable(),
                TextColumn::make('city')->sortable(),
                TextColumn::make('verification_status')->badge()->color(fn (string $state) => match ($state) {
                    'verified' => 'success', 'rejected' => 'danger', default => 'warning',
                }),
                TextColumn::make('status')->badge(),
                TextColumn::make('followers_count')->label('Followers')->numeric()->sortable(),
                TextColumn::make('completed_bookings_count')->label('Completed')->numeric()->sortable(),
                TextColumn::make('created_at')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('verification_status')->options(['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected']),
                SelectFilter::make('status')->options(['active' => 'Active', 'suspended' => 'Suspended']),
            ])
            ->recordActions([
                Action::make('verify')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Vendor $record) => $record->verification_status !== 'verified')
                    ->action(function (Vendor $record) {
                        $record->forceFill(['verification_status' => 'verified', 'verified_at' => now()])->save();
                        Notification::make()->title("{$record->name} is verified")->success()->send();
                    }),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageVendors::route('/')];
    }
}
