<?php

require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../package/autoload.php';

use App\Common\Repositories\GuiaRemisionRepository;
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

        if ($doc->estado !== EstadoDocumento::BORRADOR && $doc->estado !== EstadoDocumento::GENERADO) {
            return ['statusCode' => 409, 'body' => json_encode(['error' => 'Solo se pueden eliminar documentos en estado borrador o generado'])];
        }

        $repo->delete($docId);

        return ['statusCode' => 204, 'body' => ''];

    } catch (\Exception $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
