<?php

namespace App\Filament\Admin\Resources\Vendors\Pages;

use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Events\Models\EventType;
use App\Modules\Identity\Services\HandleRegistry;
use App\Modules\Identity\Services\VendorOnboarding;
use App\Support\PhoneNumber;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Section;

class ManageVendors extends ManageRecords
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New vendor')
                ->modalDescription('Set up a shop for a vendor. They sign in to the app with this phone number and the shop is already theirs.')
                ->schema([
                    Section::make('Owner')->columns(2)->schema([
                        TextInput::make('owner_phone')->label('Phone number')->tel()->required()->maxLength(30)
                            ->helperText('If someone already has an account with this number, they become the owner.')
                            ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) {
                                if (PhoneNumber::normalize((string) $value) === null) {
                                    $fail('Enter a valid phone number, like 024 123 4567.');
                                }
                            }),
                        TextInput::make('owner_name')->label('Name')->maxLength(80),
                    ]),
                    Section::make('Shop')->columns(2)->schema([
                        TextInput::make('name')->required()->maxLength(120),
                        TextInput::make('handle')->prefix('@')->maxLength(30)
                            ->helperText('Leave blank to make one from the name.')
                            ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) {
                                if (filled($value) && ! app(HandleRegistry::class)->isAvailable((string) $value)) {
                                    $fail('That handle is taken or invalid (3–30 letters, numbers, dots or underscores).');
                                }
                            }),
                        TextInput::make('city')->maxLength(80),
                        Select::make('categories')->multiple()
                            ->options(fn () => Category::orderBy('sort')->pluck('name', 'id')->all()),
                        Select::make('event_types')->multiple()->options(fn () => EventType::options()),
                        Textarea::make('bio')->columnSpanFull(),
                    ]),
                ])
                ->using(fn (array $data) => app(VendorOnboarding::class)->create($data)),
        ];
    }
}
