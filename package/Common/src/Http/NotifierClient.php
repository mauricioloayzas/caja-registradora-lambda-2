<?php

namespace App\Common\Http;

use GuzzleHttp\Client;

class NotifierClient
{
    private Client $http;
    private string $apiKey;
    private bool $enabled;

    public function __construct()
    {
        $base          = rtrim($_ENV['NOTIFIER_API_BASE'] ?? '', '/');
        $this->apiKey  = $_ENV['NOTIFIER_API_KEY'] ?? '';
        $this->enabled = $base !== '' && $this->apiKey !== '';
        $this->http    = new Client(['base_uri' => $base . '/', 'timeout' => 5]);
    }

    /**
     * Envía el documento electrónico autorizado al cliente por correo (XML adjunto).
     * Nunca lanza excepción: si notifier está caído o mal configurado, solo se pierde el
     * correo, no la operación de negocio que disparó esta llamada (la factura ya quedó
     * autorizada de todas formas).
     */
    public function documentoAutorizado(array $data): void
    {
        $this->post('notificaciones/documento-autorizado', $data);
    }

    /** Alerta interna al owner del profile origin (la plataforma) cuando falla cualquier fase del procesamiento SRI. */
    public function alertaFalloSri(array $data): void
    {
        $this->post('notificaciones/alerta-fallo-sri', $data);
    }

    /** Reporte de Formulario 104 (IVA mensual) recién generado — correo siempre, WhatsApp best-effort. */
    public function reporteFormulario104(array $data): void
    {
        $this->post('notificaciones/reporte-formulario-104', $data);
    }

    /** Reporte ATS recién generado — correo con el XML adjunto siempre, WhatsApp best-effort. */
    public function reporteAts(array $data): void
    {
        $this->post('notificaciones/reporte-ats', $data);
    }

    private function post(string $path, array $data): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->http->post($path, [
                'headers' => ['X-Api-Key' => $this->apiKey],
                'json'    => $data,
            ]);
        } catch (\Throwable $e) {
            error_log('NotifierClient error en ' . $path . ': ' . $e->getMessage());
        }
    }
}
