<?php

namespace App\Filament\Admin\Resources\WaitlistSignups;

use App\Filament\Admin\Resources\WaitlistSignups\Pages\ManageWaitlistSignups;
use App\Modules\Marketing\Enums\WaitlistRole;
use App\Modules\Marketing\Models\WaitlistSignup;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Landing page sign-ups. Read-only: rows only come from the public form. */
class WaitlistSignupResource extends Resource
{
    protected static ?string $model = WaitlistSignup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Waitlist';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $marketing = config('eventapp.marketing');

        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('role')->badge()
                    ->formatStateUsing(fn (WaitlistRole $state) => $state->label())
                    ->color(fn (WaitlistRole $state) => $state === WaitlistRole::Vendor ? 'info' : 'warning'),
                TextColumn::make('whatsapp_phone')->label('WhatsApp')->searchable()->copyable(),
                TextColumn::make('city')->searchable()->sortable(),
                TextColumn::make('business_name')->label('Business')->searchable()->placeholder('—'),
                TextColumn::make('category')->formatStateUsing(fn (?string $state) => $marketing['vendor_categories'][$state] ?? $state)->placeholder('—'),
                TextColumn::make('event_types')->badge()->placeholder('—'),
                TextColumn::make('source.utm_source')->label('Source')->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->label('Joined')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('role')->options(collect(WaitlistRole::cases())->mapWithKeys(fn (WaitlistRole $r) => [$r->value => $r->label()])),
                SelectFilter::make('category')->options($marketing['vendor_categories']),
            ])
            ->recordActions([
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWaitlistSignups::route('/')];
    }
}
