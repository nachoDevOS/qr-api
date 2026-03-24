<?php

namespace App\Console\Commands;

use App\Services\BnbService;
use Illuminate\Console\Command;

class BnbUpdateCredentials extends Command
{
    protected $signature   = 'bnb:update-credentials {new_password}';
    protected $description = 'Actualiza el authorizationId del BNB. Obligatorio la primera vez antes de usar los servicios.';

    public function handle(BnbService $bnb): int
    {
        $newPassword = $this->argument('new_password');

        $this->info('Conectando con el BNB...');

        $result = $bnb->updateCredentials($newPassword);

        if (! ($result['success'] ?? false)) {
            $this->error('Error: ' . ($result['message'] ?? 'Sin respuesta del banco'));
            return self::FAILURE;
        }

        $this->info('Credenciales actualizadas correctamente.');
        $this->warn('Actualizá el BNB_AUTHORIZATION_ID en el .env con el nuevo valor: ' . $newPassword);

        return self::SUCCESS;
    }
}
