<?php

require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../package/autoload.php';

use App\Common\Repositories\GuiaRemisionRepository;
use App\Common\Services\SriService;
use App\Common\Services\SriFailureAlertService;
use Mauloasan\BobConstruye\DynamoDB\Enums\CajaRegistradora\EstadoDocumento;
use App\Common\Http\ProfileAccessMiddleware;

return function (array $event) {
    $profileId = $event['pathParameters']['profile_id'] ?? null;
    $docId     = $event['pathParameters']['guiaId'] ?? null;

    if (empty($profileId) || empty($docId)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required path parameters'])];
    }

    $forbidden = ProfileAccessMiddleware::check($event, $profileId);
    if ($forbidden) {
        return $forbidden;
    }

    try {
        $repo = new GuiaRemisionRepository();
        $doc  = $repo->get($docId);

        if (!$doc) {
            return ['statusCode' => 404, 'body' => json_encode(['error' => 'Document not found'])];
        }

        if (empty($doc->xml_firmado)) {
            return ['statusCode' => 409, 'body' => json_encode(['error' => 'El documento no está firmado'])];
        }

        $sriService   = new SriService();
        $respuestaSri = $sriService->enviarSri($doc->xml_firmado, $doc->info_tributaria->ambiente->value);

        // Si el SRI rechaza en esta fase (envío), se guarda su respuesta completa igual que
        // hace authorize.php cuando rechaza en la fase de autorización — antes se perdía y
        // quedaba "rechazado" sin ninguna pista de por qué.
        if (($respuestaSri['estado'] ?? '') === 'RECIBIDA') {
            $doc = $repo->updateStatus($docId, EstadoDocumento::ENVIADO->value);
        } else {
            $doc = $repo->updateRechazo($docId, json_encode($respuestaSri));
            SriFailureAlertService::notificar('guía de remisión', 'envio', $profileId, $doc->clave_acceso, json_encode($respuestaSri));
        }

        return [
            'statusCode' => 200,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => json_encode(['data' => $doc->toArray(), 'respuesta_sri' => $respuestaSri]),
        ];

    } catch (\Exception $e) {
        SriFailureAlertService::notificar('guía de remisión', 'envio', $profileId, $doc->clave_acceso ?? $docId, $e->getMessage());
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
