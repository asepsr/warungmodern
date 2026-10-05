<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use App\Models\StockMovement;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_path')
                    ->label('Foto')
                    ->disk('public')
                    ->square(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),
                TextColumn::make('name')
                    ->label('Produk')
                    ->description(fn (Product $record): ?string => $record->description
                        ? Str::limit($record->description, 40)
                        : null)
                    ->width('18rem')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('price')
                    ->label('Harga')
                    ->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.'))
                    ->sortable(),
                TextColumn::make('stock')
                    ->label('Stok')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (Product $record): string => $record->stock <= $record->min_stock ? 'danger' : 'success'),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Kategori')
                    ->relationship('category', 'name'),
                Filter::make('low_stock')
                    ->label('Stok menipis')
                    ->query(fn (Builder $query): Builder => $query->whereColumn('stock', '<=', 'min_stock')),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('adjustStock')
                    ->label('Atur stok')
                    ->icon('heroicon-o-arrows-right-left')
                    ->form([
                        Select::make('operation')
                            ->label('Jenis penyesuaian')
                            ->options(['add' => 'Tambah stok', 'remove' => 'Kurangi stok', 'set' => 'Atur jumlah akhir'])
                            ->required(),
                        TextInput::make('quantity')
                            ->label('Jumlah')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->required(),
                    ])
                    ->action(function (Product $record, array $data): void {
                        DB::transaction(function () use ($record, $data): void {
                            $product = Product::query()->lockForUpdate()->findOrFail($record->id);
                            $before = $product->stock;
                            $quantity = (int) $data['quantity'];
                            $after = match ($data['operation']) {
                                'add' => $before + $quantity,
                                'remove' => $before - $quantity,
                                default => $quantity,
                            };

                            if ($after < 0) {
                                throw ValidationException::withMessages(['quantity' => 'Stok tidak boleh kurang dari nol.']);
                            }

                            $product->update(['stock' => $after]);
                            StockMovement::create([
                                'product_id' => $product->id,
                                'type' => $data['operation'] === 'remove' ? 'adjust' : ($data['operation'] === 'add' ? 'restock' : 'adjust'),
                                'quantity' => abs($after - $before),
                                'stock_before' => $before,
                                'stock_after' => $after,
                                'note' => 'Penyesuaian stok dari dashboard.',
                                'created_by' => auth()->id(),
                            ]);
                        });

                        Notification::make()->title('Stok berhasil diperbarui')->success()->send();
                    }),
            ])
            ->defaultSort('name');
    }
}
