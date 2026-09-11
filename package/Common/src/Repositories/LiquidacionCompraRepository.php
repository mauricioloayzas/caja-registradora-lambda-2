<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Mauloasan\BobConstruye\DynamoDB\Entities\CajaRegistradora\LiquidacionCompraEntity;
use Mauloasan\BobConstruye\DynamoDB\Enums\CajaRegistradora\EstadoDocumento;
use Mauloasan\BobConstruye\DynamoDB\PaginatedProfileQueryTrait;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;

class LiquidacionCompraRepository
{
    use PaginatedProfileQueryTrait;

    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient  = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_LIQUIDACIONES_COMPRA'];
    }

    public function get(string $id): ?LiquidacionCompraEntity
    {
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        if (empty($result['Item'])) {
            return null;
        }

        return LiquidacionCompraEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));
    }

    public function getAll(): ?array
    {
        $result = $this->dbClient->scan(['TableName' => $this->tableName]);

        if (empty($result['Items'])) {
            return null;
        }

        return array_map(
            fn($item) => LiquidacionCompraEntity::fromArray($this->marshaler->unmarshalItem($item)),
            $result['Items']
        );
    }

    public function create(LiquidacionCompraEntity $liquidacion): ?LiquidacionCompraEntity
    {
        $this->dbClient->putItem([
            'TableName' => $this->tableName,
            'Item'      => $this->marshaler->marshalItem($liquidacion->toArray()),
        ]);

        return $this->get($liquidacion->id);
    }

    public function getByProfileId(string $profileId): ?array
    {
        $result = $this->dbClient->query([
            'TableName'                 => $this->tableName,
            'IndexName'                 => 'profile_id-index',
            'KeyConditionExpression'    => 'profile_id = :profile_id',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([':profile_id' => $profileId]),
        ]);

        if (empty($result['Items'])) {
            return null;
        }

        return array_map(
            fn($item) => LiquidacionCompraEntity::fromArray($this->marshaler->unmarshalItem($item)),
            $result['Items']
        );
    }

    /**
     * Versión paginada de getByProfileId(), sobre el GSI profile_id-created_at-index (más
     * reciente primero), con búsqueda opcional por proveedor/número/clave de acceso.
     */
    public function queryByProfileId(string $profileId, ?int $limit = null, ?string $cursor = null, ?string $search = null): array
    {
        return $this->queryPaginated(
            'profile_id-created_at-index',
            $profileId,
            fn($data) => LiquidacionCompraEntity::fromArray($data),
            $limit,
            $cursor,
            $search,
            ['info_liquidacion_compra.razonSocialProveedor', 'info_tributaria.secuencial', 'clave_acceso']
        );
    }

    public function getByApplicationId(string $applicationId): ?array
    {
        $result = $this->dbClient->query([
            'TableName'                 => $this->tableName,
            'IndexName'                 => 'application_id-index',
            'KeyConditionExpression'    => 'application_id = :application_id',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([':application_id' => $applicationId]),
        ]);

        if (empty($result['Items'])) {
            return null;
        }

        return array_map(
            fn($item) => LiquidacionCompraEntity::fromArray($this->marshaler->unmarshalItem($item)),
            $result['Items']
        );
    }

    public function updateStatus(string $id, string $status): ?LiquidacionCompraEntity
    {
        if (EstadoDocumento::tryFrom($status) === null) {
            throw new \InvalidArgumentException('Estado inválido: ' . $status);
        }

        $this->dbClient->updateItem([
            'TableName'                 => $this->tableName,
            'Key'                       => $this->marshaler->marshalItem(['id' => $id]),
            'UpdateExpression'          => 'SET #estado = :estado, updated_at = :updated_at',
            'ExpressionAttributeNames'  => ['#estado' => 'estado'],
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                ':estado'     => $status,
                ':updated_at' => date('c'),
            ]),
        ]);

        return $this->get($id);
    }

    public function updateXml(string $id, ?string $xmlGenerado = null, ?string $xmlFirmado = null): ?LiquidacionCompraEntity
    {
        $updates    = [];
        $attrValues = [':updated_at' => date('c')];

        if ($xmlGenerado !== null) {
            $updates[]                   = 'xml_generado = :xml_generado';
            $attrValues[':xml_generado'] = $xmlGenerado;
        }

        if ($xmlFirmado !== null) {
            $updates[]                  = 'xml_firmado = :xml_firmado';
            $attrValues[':xml_firmado'] = $xmlFirmado;
        }

        $updates[] = 'updated_at = :updated_at';

        $this->dbClient->updateItem([
            'TableName'                 => $this->tableName,
            'Key'                       => $this->marshaler->marshalItem(['id' => $id]),
            'UpdateExpression'          => 'SET ' . implode(', ', $updates),
            'ExpressionAttributeValues' => $this->marshaler->marshalItem($attrValues),
        ]);

        return $this->get($id);
    }

    public function updateAutorizacion(string $id, string $numeroAutorizacion, string $fechaAutorizacion, string $respuestaSri): ?LiquidacionCompraEntity
    {
        $this->dbClient->updateItem([
            'TableName'                 => $this->tableName,
            'Key'                       => $this->marshaler->marshalItem(['id' => $id]),
            'UpdateExpression'          => 'SET numero_autorizacion = :num_aut, fecha_autorizacion = :fecha_aut, respuesta_sri = :resp_sri, #estado = :estado, updated_at = :updated_at',
            'ExpressionAttributeNames'  => ['#estado' => 'estado'],
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                ':num_aut'    => $numeroAutorizacion,
                ':fecha_aut'  => $fechaAutorizacion,
                ':resp_sri'   => $respuestaSri,
                ':estado'     => EstadoDocumento::AUTORIZADO->value,
                ':updated_at' => date('c'),
            ]),
        ]);

        return $this->get($id);
    }

    public function updateRechazo(string $id, string $respuestaSri): ?LiquidacionCompraEntity
    {
        $this->dbClient->updateItem([
            'TableName'                 => $this->tableName,
            'Key'                       => $this->marshaler->marshalItem(['id' => $id]),
            'UpdateExpression'          => 'SET respuesta_sri = :resp_sri, #estado = :estado, updated_at = :updated_at',
            'ExpressionAttributeNames'  => ['#estado' => 'estado'],
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                ':resp_sri'   => $respuestaSri,
                ':estado'     => EstadoDocumento::RECHAZADO->value,
                ':updated_at' => date('c'),
            ]),
        ]);

        return $this->get($id);
    }

    public function delete(string $id): bool
    {
        $this->dbClient->deleteItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        return true;
    }
}
