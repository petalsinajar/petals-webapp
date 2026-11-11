<?php

namespace App\Filament\Admin\Resources\Bookings;

use App\Filament\Admin\Resources\Bookings\Pages\ManageBookings;
use App\Modules\Bookings\Enums\BookingStatus;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $recordTitleAttribute = 'number';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('vendor.name')->searchable(),
                TextColumn::make('customer_name')->label('Customer')->state(fn (Booking $record) => $record->customerName()),
                TextColumn::make('status')->badge()->formatStateUsing(fn (BookingStatus $state) => str($state->value)->replace('_', ' ')->title()),
                TextColumn::make('total_minor')->label('Total')->formatStateUsing(fn (int $state, Booking $record) => $record->currency.' '.number_format($state / 100, 2))->sortable(),
                TextColumn::make('paid_minor')->label('Paid')->formatStateUsing(fn (int $state, Booking $record) => $record->currency.' '.number_format($state / 100, 2)),
                TextColumn::make('window_start')->label('Delivery')->dateTime()->sortable(),
                TextColumn::make('created_at')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(collect(BookingStatus::cases())->mapWithKeys(fn ($s) => [$s->value => str($s->value)->replace('_', ' ')->title()->toString()])),
            ])
            ->recordActions([
                // Disputes: decide how much of the damage claim the vendor keeps, then complete.
                Action::make('resolveDispute')
                    ->label('Resolve dispute')
                    ->icon(Heroicon::OutlinedScale)
                    ->color('warning')
                    ->visible(fn (Booking $record) => $record->status === BookingStatus::Disputed)
                    ->fillForm(fn (Booking $record) => ['damage_claim_minor' => $record->condition['damage_claim_minor'] ?? 0])
                    ->schema([
                        TextInput::make('damage_claim_minor')->label('Damage paid to vendor (minor units)')->integer()->minValue(0)->required(),
                        Textarea::make('note')->required(),
                    ])
                    ->action(function (Booking $record, array $data) {
                        $record->condition = [...($record->condition ?? []), 'damage_claim_minor' => (int) min($data['damage_claim_minor'], $record->damage_deposit_minor)];
                        $record->save();
                        app(BookingService::class)->complete($record, Auth::user(), $data['note']);
                        Notification::make()->title('Dispute resolved')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageBookings::route('/')];
    }
}
