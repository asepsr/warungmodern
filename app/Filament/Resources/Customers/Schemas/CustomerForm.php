<?php

namespace App\Filament\Resources\Customers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('phone')
                    ->label('Nomor telepon')
                    ->tel()
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->label('Nama pelanggan')
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email')
                    ->email(),
            ]);
    }
}
