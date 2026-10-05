<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi produk')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama produk')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('sku')
                            ->label('SKU')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        FileUpload::make('image_path')
                            ->label('Gambar produk')
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->directory('products')
                            ->visibility('public')
                            ->maxSize(5120)
                            ->columnSpanFull(),
                        Select::make('category_id')
                            ->label('Kategori')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload(),
                        TextInput::make('unit')
                            ->label('Satuan')
                            ->required()
                            ->default('pcs')
                            ->maxLength(40),
                        TextInput::make('price')
                            ->label('Harga jual')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->prefix('Rp'),
                        Toggle::make('is_active')
                            ->label('Produk aktif')
                            ->default(true),
                        TagsInput::make('aliases')
                            ->label('Nama lain / kata kunci')
                            ->placeholder('Contoh: migor, minyak')
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->label('Deskripsi produk')
                            ->maxLength(2000)
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Persediaan')
                    ->columns(2)
                    ->schema([
                        TextInput::make('stock')
                            ->label('Stok tersedia')
                            ->required()
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->disabledOn('edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                        TextInput::make('min_stock')
                            ->label('Batas stok menipis')
                            ->required()
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->default(0),
                    ]),
            ]);
    }
}
