<?php

namespace App\Common\Services;

use App\Common\Http\NotifierClient;
use App\Common\Repositories\ProfileRepository;

/**
 * Alerta al owner del profile "origin" (la plataforma, no el negocio afectado) cuando falla
 * cualquier fase del procesamiento SRI de cualquier documento — sea un rechazo del SRI o una
 * excepción técnica (SRI caído, XML mal formado, etc). Fire-and-forget de punta a punta:
 * nunca debe interrumpir ni hacer fallar el flujo real de facturación de nadie.
 */
class SriFailureAlertService
{
    public static function notificar(
        string $tipoComprobante,
        string $fase,
        string $profileId,
        string $claveAcceso,
        string $motivo
    ): void {
        try {
            $serviceProfileId = $_ENV['SERVICE_PROFILE_ID'] ?? '';
            if (empty($serviceProfileId)) {
                return;
            }

            $origin = (new ProfileRepository())->get($serviceProfileId);
            if (!$origin || empty($origin->email)) {
                return;
            }

            $negocio = (new ProfileRepository())->get($profileId);

            (new NotifierClient())->alertaFalloSri([
                'to'               => $origin->email,
                'tipo_comprobante' => $tipoComprobante,
                'fase'             => $fase,
                'profile_id'       => $profileId,
                'nombre_negocio'   => $negocio->name ?? $profileId,
                'clave_acceso'     => $claveAcceso,
                'motivo'           => $motivo,
                'ambiente'         => $_ENV['STAGE'] ?? '',
            ]);
        } catch (\Throwable $e) {
            error_log('SriFailureAlertService error: ' . $e->getMessage());
        }
    }
}
