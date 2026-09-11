<?php

require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../package/autoload.php';

use App\Common\Repositories\GuiaRemisionRepository;
use App\Common\Repositories\ProfileRepository;
use App\Common\Services\SriService;
use App\Common\Services\SriFailureAlertService;
use Mauloasan\BobConstruye\DynamoDB\Enums\CajaRegistradora\EstadoDocumento;
use App\Common\Http\ProfileAccessMiddleware;

return function (array $event) {
    $profileId = $event['pathParameters']['profile_id'] ?? null;
    $docId     = $event['pathParameters']['guiaId'] ?? null;
    $body      = json_decode($event['body'] ?? '', true);

    if (empty($profileId) || empty($docId)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required path parameters'])];
    }

    $forbidden = ProfileAccessMiddleware::check($event, $profileId);
    if ($forbidden) {
        return $forbidden;
    }

    $certificadoP12Base64 = $body['certificado_p12_base64'] ?? null;
    $claveCertificado      = $body['clave_certificado'] ?? null;

    if (empty($certificadoP12Base64) || empty($claveCertificado)) {
        $certificado = (new ProfileRepository())->getCertificadoElectronico($profileId);

        if (!$certificado) {
            return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required fields: certificado_p12_base64, clave_certificado'])];
        }

        $certificadoP12Base64 = $certificado['certificado_p12_base64'];
        $claveCertificado      = $certificado['clave_certificado'];
    }

    try {
        $repo = new GuiaRemisionRepository();
        $doc  = $repo->get($docId);

        if (!$doc) {
            return ['statusCode' => 404, 'body' => json_encode(['error' => 'Document not found'])];
        }

        if (empty($doc->xml_generado)) {
            return ['statusCode' => 409, 'body' => json_encode(['error' => 'El documento no tiene XML generado'])];
        }

        $sriService = new SriService();
        $xmlFirmado = $sriService->firmarXml(
            $doc->tipo_comprobante->name,
            $doc->xml_generado,
            $certificadoP12Base64,
            $claveCertificado
        );

        $doc = $repo->updateXml($docId, null, $xmlFirmado);
        $doc = $repo->updateStatus($docId, EstadoDocumento::FIRMADO->value);

        return [
            'statusCode' => 200,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => json_encode(['data' => $doc->toArray()]),
        ];

    } catch (\Exception $e) {
        SriFailureAlertService::notificar('guía de remisión', 'firma', $profileId, $doc->clave_acceso ?? $docId, $e->getMessage());
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
