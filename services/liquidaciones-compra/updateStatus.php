<?php

require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../package/autoload.php';

use App\Common\Repositories\LiquidacionCompraRepository;
use App\Common\Http\ProfileAccessMiddleware;

return function (array $event) {
    $profileId = $event['pathParameters']['profile_id'] ?? null;
    $docId     = $event['pathParameters']['liquidacionId'] ?? null;
    $body      = json_decode($event['body'] ?? '', true);

    if (empty($profileId) || empty($docId)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required path parameters'])];
    }

    $forbidden = ProfileAccessMiddleware::check($event, $profileId);
    if ($forbidden) {
        return $forbidden;
    }

    if (empty($body['estado'])) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required field: estado'])];
    }

    try {
        $repo = new LiquidacionCompraRepository();
        $doc  = $repo->updateStatus($docId, $body['estado']);

        if (!$doc) {
            return ['statusCode' => 404, 'body' => json_encode(['error' => 'Document not found'])];
        }

        return [
            'statusCode' => 200,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => json_encode(['data' => $doc->toArray()]),
        ];

    } catch (\InvalidArgumentException $e) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => $e->getMessage()])];
    } catch (\Exception $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
