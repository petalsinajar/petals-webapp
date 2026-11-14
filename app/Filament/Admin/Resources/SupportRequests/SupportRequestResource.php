<?php

namespace App\Filament\Admin\Resources\SupportRequests;

use App\Filament\Admin\Resources\SupportRequests\Pages\ManageSupportRequests;
use App\Modules\Marketing\Enums\SupportTopic;
use App\Modules\Marketing\Models\SupportRequest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Messages from the public support page. Rows only come from the form; the team resolves them. */
class SupportRequestResource extends Resource
{
    protected static ?string $model = SupportRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?string $navigationLabel = 'Support';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = SupportRequest::whereNull('resolved_at')->count();

        return $open ? (string) $open : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'resolver']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('topic')->formatStateUsing(fn (SupportTopic $state) => $state->label())->badge(),
            TextEntry::make('created_at')->label('Received')->dateTime(),
            TextEntry::make('name'),
            TextEntry::make('user.handle')->label('Matching account')->prefix('@')->placeholder('None found')
                ->helperText('Matched on the details given. Confirm through the account’s own phone or email before acting.'),
            TextEntry::make('email')->copyable()->placeholder('—'),
            TextEntry::make('phone')->copyable()->placeholder('—'),
            TextEntry::make('message')->columnSpanFull()->prose(),
            TextEntry::make('resolved_at')->label('Resolved')->dateTime()->placeholder('Open'),
            TextEntry::make('resolver.display_name')->label('Resolved by')->placeholder('—'),
            TextEntry::make('resolution_note')->label('Note')->columnSpanFull()->placeholder('—'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Received')->since()->sortable(),
                TextColumn::make('topic')->badge()
                    ->formatStateUsing(fn (SupportTopic $state) => $state->label())
                    ->color(fn (SupportTopic $state) => $state === SupportTopic::AccountDeletion ? 'danger' : 'gray'),
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable()->placeholder('—')->toggleable(),
                TextColumn::make('phone')->searchable()->placeholder('—')->toggleable(),
                TextColumn::make('user.handle')->label('Account')->prefix('@')->placeholder('—'),
                TextColumn::make('message')->limit(60)->wrap(),
                TextColumn::make('resolved_at')->label('Status')->badge()
                    ->state(fn (SupportRequest $record) => $record->resolved_at ? 'Resolved' : 'Open')
                    ->color(fn (string $state) => $state === 'Open' ? 'warning' : 'success'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('resolved')->label('Resolved')->nullable()->attribute('resolved_at')
                    ->default(false),
                SelectFilter::make('topic')->options(collect(SupportTopic::cases())->mapWithKeys(fn (SupportTopic $t) => [$t->value => $t->label()])),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('resolve')
                    ->icon(Heroicon::OutlinedCheck)
                    ->visible(fn (SupportRequest $record) => $record->resolved_at === null)
                    ->schema([Textarea::make('note')->label('What was done')->maxLength(2000)])
                    ->action(fn (SupportRequest $record, array $data) => $record->forceFill([
                        'resolved_at' => now(),
                        'resolved_by' => auth()->id(),
                        'resolution_note' => $data['note'] ?? null,
                    ])->save()),
                Action::make('reopen')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->visible(fn (SupportRequest $record) => $record->resolved_at !== null)
                    ->requiresConfirmation()
                    ->action(fn (SupportRequest $record) => $record->forceFill(['resolved_at' => null, 'resolved_by' => null])->save()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSupportRequests::route('/')];
    }
}
