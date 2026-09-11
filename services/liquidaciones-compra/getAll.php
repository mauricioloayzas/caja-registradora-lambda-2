<?php

require_once file_exists('/opt/vendor/autoload.php') ? '/opt/vendor/autoload.php' : __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../package/autoload.php';

use App\Common\Repositories\LiquidacionCompraRepository;
use App\Common\Http\ProfileAccessMiddleware;
use Mauloasan\BobConstruye\Http\PaginationParams;

return function (array $event) {
    $profileId = $event['pathParameters']['profile_id'] ?? null;

    if (empty($profileId)) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => 'Missing required profile_id'])];
    }

    $forbidden = ProfileAccessMiddleware::check($event, $profileId);
    if ($forbidden) {
        return $forbidden;
    }

    try {
        $repo = new LiquidacionCompraRepository();
        $page = PaginationParams::parse($event);

        // Sin ?limit ni ?search no se pagina, se mantiene el comportamiento de siempre (trae
        // todo) — lo usan los selects de otras pantallas que necesitan la lista completa.
        if ($page['limit'] === null && $page['search'] === null) {
            $docs = $repo->getByProfileId($profileId);

            return [
                'statusCode' => 200,
                'headers'    => ['Content-Type' => 'application/json'],
                'body'       => json_encode(['data' => $docs ? array_map(fn($d) => $d->toArray(), $docs) : []]),
            ];
        }

        $result = $repo->queryByProfileId($profileId, $page['limit'] ?? 20, $page['cursor'], $page['search']);

        return [
            'statusCode' => 200,
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => json_encode([
                'data'        => array_map(fn($d) => $d->toArray(), $result['items']),
                'next_cursor' => $result['next_cursor'],
                'has_more'    => $result['next_cursor'] !== null,
            ]),
        ];

    } catch (\InvalidArgumentException $e) {
        return ['statusCode' => 400, 'body' => json_encode(['error' => $e->getMessage()])];
    } catch (\Exception $e) {
        return ['statusCode' => 500, 'body' => json_encode(['error' => $e->getMessage()])];
    }
};
