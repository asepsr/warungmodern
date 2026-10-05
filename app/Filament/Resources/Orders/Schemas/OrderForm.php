<?php

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('order_number')
                    ->required(),
                Select::make('customer_id')
                    ->relationship('customer', 'name'),
                Select::make('address_id')
                    ->relationship('address', 'id'),
                TextInput::make('channel')
                    ->required()
                    ->default('whatsapp'),
                TextInput::make('status')
                    ->required()
                    ->default('pending_payment'),
                TextInput::make('subtotal')
                    ->required()
                    ->numeric(),
                TextInput::make('delivery_fee')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('unique_code')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('total')
                    ->required()
                    ->numeric(),
                TextInput::make('distance_km')
                    ->numeric(),
                TextInput::make('idempotency_key'),
                DateTimePicker::make('expired_at'),
                DateTimePicker::make('paid_at'),
                DateTimePicker::make('shipped_at'),
                DateTimePicker::make('completed_at'),
                TextInput::make('cancel_reason'),
            ]);
    }
}
