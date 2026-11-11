<?php

namespace App\Filament\Vendor\Resources\Bookings;

use App\Filament\Vendor\Resources\Bookings\Pages\ManageBookings;
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
use Throwable;

/**
 * The vendor's booking schedule. Every action goes through BookingService, exactly like the
 * app, so the same rules and stock handling apply on web and mobile.
 */
class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'number';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $money = fn (int $state, Booking $record) => $record->currency.' '.number_format($state / 100, 2);

        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (BookingStatus $state) => str($state->value)->replace('_', ' ')->title())
                    ->color(fn (BookingStatus $state) => match ($state) {
                        BookingStatus::PendingVendor => 'warning',
                        BookingStatus::Confirmed, BookingStatus::Completed => 'success',
                        BookingStatus::Declined, BookingStatus::Cancelled, BookingStatus::Expired => 'gray',
                        BookingStatus::Disputed => 'danger',
                        default => 'info',
                    }),
                TextColumn::make('customer_name')->label('Customer')->state(fn (Booking $record) => $record->customerName()),
                TextColumn::make('window_start')->label('Delivery')->dateTime('D j M, H:i')->sortable(),
                TextColumn::make('window_end')->label('Collection')->dateTime('D j M, H:i'),
                TextColumn::make('fulfilment.type')->label('Fulfilment')->badge(),
                TextColumn::make('total_minor')->label('Total')->formatStateUsing($money),
                TextColumn::make('paid_minor')->label('Paid')->formatStateUsing($money),
                TextColumn::make('vendor_respond_by')->label('Respond by')->since(),
            ])
            ->defaultSort('window_start')
            ->filters([
                SelectFilter::make('status')->multiple()->options(collect(BookingStatus::cases())
                    ->mapWithKeys(fn ($s) => [$s->value => str($s->value)->replace('_', ' ')->title()->toString()])),
            ])
            ->recordActions([
                self::step('accept', 'Accept', Heroicon::OutlinedCheck, 'success', [BookingStatus::PendingVendor],
                    fn (BookingService $s, Booking $b) => $s->accept($b, Auth::user())),
                self::step('decline', 'Decline', Heroicon::OutlinedXMark, 'danger', [BookingStatus::PendingVendor],
                    fn (BookingService $s, Booking $b, array $d) => $s->decline($b, Auth::user(), $d['reason'] ?? null),
                    [Textarea::make('reason')]),
                self::step('dispatch', 'Out for delivery', Heroicon::OutlinedTruck, 'info', [BookingStatus::Confirmed],
                    fn (BookingService $s, Booking $b) => $s->dispatch($b, Auth::user())),
                self::step('deliver', 'Delivered', Heroicon::OutlinedCheckBadge, 'info', [BookingStatus::Confirmed, BookingStatus::OutForDelivery],
                    fn (BookingService $s, Booking $b) => $s->deliver($b, Auth::user())),
                self::step('return', 'Items returned', Heroicon::OutlinedArrowUturnLeft, 'success', [BookingStatus::Delivered],
                    fn (BookingService $s, Booking $b, array $d) => $s->markReturned($b, Auth::user(), (int) ($d['damage_claim_minor'] ?? 0), [], $d['note'] ?? null),
                    [
                        TextInput::make('damage_claim_minor')->label('Damage claim (minor units, 0 if none)')->integer()->minValue(0)->default(0),
                        Textarea::make('note'),
                    ]),
            ]);
    }

    /**
     * @param  list<BookingStatus>  $from
     * @param  array<int, mixed>  $schema
     */
    private static function step(string $name, string $label, Heroicon $icon, string $color, array $from, callable $run, array $schema = []): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation($schema === [])
            ->schema($schema)
            ->visible(fn (Booking $record) => in_array($record->status, $from, true))
            ->action(function (Booking $record, array $data) use ($run, $label) {
                try {
                    $run(app(BookingService::class), $record, $data);
                    Notification::make()->title("{$label}: {$record->number}")->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Could not update booking')->body($e->getMessage())->danger()->send();
                }
            });
    }

    public static function getPages(): array
    {
        return ['index' => ManageBookings::route('/')];
    }
}
