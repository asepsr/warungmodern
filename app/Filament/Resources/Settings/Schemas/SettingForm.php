<?php

namespace App\Filament\Resources\Settings\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->label('Kunci pengaturan')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->disabledOn('edit')
                    ->dehydrated(),
                FileUpload::make('value')
                    ->label('Gambar QRIS statis')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->disk('public')
                    ->directory('qris')
                    ->visibility('public')
                    ->maxSize(5120)
                    ->helperText('Unggah gambar QRIS statis dari penyedia pembayaran. QR nominal dibuat saat pelanggan checkout.')
                    ->visible(fn ($get): bool => $get('key') === 'qris_image')
                    ->required(fn ($get): bool => $get('key') === 'qris_image')
                    ->columnSpanFull(),
                Textarea::make('value')
                    ->label('Nilai')
                    ->rows(4)
                    ->visible(fn ($get): bool => $get('key') !== 'qris_image')
                    ->columnSpanFull(),
            ]);
    }
}
