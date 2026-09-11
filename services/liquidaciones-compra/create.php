<?php

require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../package/autoload.php';

use App\Common\Repositories\LiquidacionCompraRepository;
use App\Common\Repositories\ProfileRepository;
use App\Common\Repositories\PuntoEmisionRepository;
use App\Common\Repositories\SecuencialRepository;
use App\Common\Services\SriService;
use Aws\Sfn\SfnClient;
use Mauloasan\BobConstruye\DynamoDB\Entities\CajaRegistradora\LiquidacionCompraEntity;
use Mauloasan\BobConstruye\DynamoDB\Enums\CajaRegistradora\EstadoDocumento;
use Mauloasan\BobConstruye\DynamoDB\Enums\CajaRegistradora\PuntoEmisionStatus;
use Mauloasan\BobConstruye\DynamoDB\Enums\CajaRegistradora\TipoComprobante;
use Mauloasan\BobConstruye\DynamoDB\DTOs\CajaRegistradora\InfoTributariaDTO;
use Mauloasan\BobConstruye\DynamoDB\DTOs\CajaRegistradora\InfoLiquidacionCompraDTO;
use Mauloasan\BobConstruye\DynamoDB\DTOs\CajaRegistradora\DetalleDTO;
use Ramsey\Uuid\Uuid;
use App\Common\Http\ProfileAccessMiddleware;

return function (array $event) {
    $profileId = $event['pathParameters']['profile_id'] ?? null;
    $body      = json_decode($event['body'] ?? '', true);

    if (empty($profileId)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profile_id'])];
    }

    $forbidden = ProfileAccessMiddleware::check($event, $profileId);
    if ($forbidden) {
        return $forbidden;
    }

    if (
        empty($body['application_id'])           ||
        empty($body['info_tributaria'])          ||
        empty($body['info_liquidacion_compra'])  ||
        empty($body['detalle'])
    ) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required fields: application_id, info_tributaria, info_liquidacion_compra, detalle'])];
    }

    $profile = (new ProfileRepository())->get($profileId);

    if (!$profile) {
        return ['statusCode' => 404, 'body' => json_encode(['error' => 'Profile not found'])];
    }

    if (empty($profile->tax_id) || empty($profile->tax_id_type)) {
        return ['statusCode' => 409, 'body' => json_encode(['error' => 'El perfil no tiene tax_id/tax_id_type configurado'])];
    }

    if ($profile->tax_id_type !== 'RUC') {
        return ['statusCode' => 409, 'body' => json_encode(['error' => 'Solo perfiles con tax_id_type RUC pueden emitir documentos electrónicos'])];
    }

    $body['info_tributaria']['ruc'] = $profile->tax_id;

    $estab  = $body['info_tributaria']['estab']  ?? null;
    $ptoEmi = $body['info_tributaria']['ptoEmi'] ?? null;

    if (empty($estab) || empty($ptoEmi)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required info_tributaria.estab or info_tributaria.ptoEmi'])];
    }

    $puntoEmision = (new PuntoEmisionRepository())->findByEstabAndPtoEmi($profileId, $estab, $ptoEmi);

    if (!$puntoEmision) {
        return ['statusCode' => 404, 'body' => json_encode(['error' => 'No existe un punto de emisión para este estab/ptoEmi'])];
    }

    if ($puntoEmision->status !== PuntoEmisionStatus::ACTIVE) {
        return ['statusCode' => 409, 'body' => json_encode(['error' => 'El punto de emisión está inactivo'])];
    }

    $body['info_tributaria']['secuencial'] = (new SecuencialRepository())
        ->getNext($profileId, $estab, $ptoEmi, TipoComprobante::LIQUIDACION_COMPRA->value, $puntoEmision->id);

    try {
        $infoTributaria        = InfoTributariaDTO::fromArray($body['info_tributaria']);
        $infoLiquidacionCompra = InfoLiquidacionCompraDTO::fromArray($body['info_liquidacion_compra']);
        $detalle               = array_map(fn($d) => DetalleDTO::fromArray($d), $body['detalle']);
        $infoAdicional         = $body['info_adicional'] ?? [];

        $sriService = new SriService();

        $xmlPayload = array_merge(
            ['infoTributaria' => $body['info_tributaria']],
            ['infoLiquidacionCompra' => $body['info_liquidacion_compra']],
            ['detalle' => $body['detalle']],
            ['infoAdicional' => $infoAdicional]
        );

        $xmlGenerado = $sriService->generarXml('liquidacionCompra', $xmlPayload);
        $claveAcceso = $infoTributaria->claveAcceso;
        if (empty($claveAcceso)) {
            $claveAcceso = $sriService->extraerClaveAcceso($xmlGenerado) ?? '';
        }

        $liquidacion = new LiquidacionCompraEntity(
            Uuid::uuid4()->toString(),
            $profileId,
            $body['application_id'],
            EstadoDocumento::GENERADO,
            $claveAcceso,
            $infoTributaria,
            $infoLiquidacionCompra,
            $detalle,
            $infoAdicional,
            '',
            null,
            $xmlGenerado
        );

        $repo   = new LiquidacionCompraRepository();
        $result = $repo->create($liquidacion);

        try {
            (new SfnClient(['region' => $_ENV['AWS_REGION'] ?? 'us-east-1', 'version' => 'latest']))->startExecution([
                'stateMachineArn' => $_ENV['LIQUIDACIONES_COMPRA_STATE_MACHINE_ARN'],
                'input'           => json_encode([
                    'profileId'     => $profileId,
                    'liquidacionId' => $result->id,
                    'intentos'      => 0,
                ]),
            ]);
        } catch (\Throwable $e) {
            // No bloqueamos la creación si no se pudo arrancar el procesamiento automático.
        }

        return [
            'statusCode' => 201,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => json_encode(['data' => $result->toArray()]),
        ];

    } catch (\InvalidArgumentException $e) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => $e->getMessage()])];
    } catch (\Exception $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
