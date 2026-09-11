<?php

require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../package/autoload.php';

use App\Common\Repositories\GuiaRemisionRepository;
use App\Common\Http\ProfileAccessMiddleware;

return function (array $event) {
    $profileId     = $event['pathParameters']['profile_id'] ?? null;
    $applicationId = $event['pathParameters']['applicationId'] ?? null;

    if (empty($profileId) || empty($applicationId)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required path parameters'])];
    }

    $forbidden = ProfileAccessMiddleware::check($event, $profileId);
    if ($forbidden) {
        return $forbidden;
    }

    try {
        $repo = new GuiaRemisionRepository();
        $docs = $repo->getByApplicationId($applicationId);

        return [
            'statusCode' => 200,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => json_encode(['data' => $docs ? array_map(fn($d) => $d->toArray(), $docs) : []]),
        ];

    } catch (\Exception $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
