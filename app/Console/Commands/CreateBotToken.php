<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateBotToken extends Command
{
    protected $signature = 'warung:create-bot-token {email=owner@warung.local}';

    protected $description = 'Buat Sanctum token ber-ability bot untuk pemanggilan API server';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->firstOrFail();
        $token = $user->createToken('warung-bot', ['bot'])->plainTextToken;

        $this->info('Simpan token ini sebagai secret di n8n. Token hanya ditampilkan sekali:');
        $this->line($token);

        return self::SUCCESS;
    }
}
