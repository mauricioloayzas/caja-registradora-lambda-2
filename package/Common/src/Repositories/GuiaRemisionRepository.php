<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Mauloasan\BobConstruye\DynamoDB\Entities\CajaRegistradora\GuiaRemisionEntity;
use Mauloasan\BobConstruye\DynamoDB\Enums\CajaRegistradora\EstadoDocumento;
use Mauloasan\BobConstruye\DynamoDB\PaginatedProfileQueryTrait;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;

class GuiaRemisionRepository
{
    use PaginatedProfileQueryTrait;

    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient  = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_GUIAS_REMISION'];
    }

    public function get(string $id): ?GuiaRemisionEntity
    {
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        if (empty($result['Item'])) {
            return null;
        }

        return GuiaRemisionEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));
    }

    public function getAll(): ?array
    {
        $result = $this->dbClient->scan(['TableName' => $this->tableName]);

        if (empty($result['Items'])) {
            return null;
        }

        return array_map(
            fn($item) => GuiaRemisionEntity::fromArray($this->marshaler->unmarshalItem($item)),
            $result['Items']
        );
    }

    public function create(GuiaRemisionEntity $guia): ?GuiaRemisionEntity
    {
        $this->dbClient->putItem([
            'TableName' => $this->tableName,
            'Item'      => $this->marshaler->marshalItem($guia->toArray()),
        ]);

        return $this->get($guia->id);
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
            fn($item) => GuiaRemisionEntity::fromArray($this->marshaler->unmarshalItem($item)),
            $result['Items']
        );
    }

    /**
     * Versión paginada de getByProfileId(), sobre el GSI profile_id-created_at-index (más
     * reciente primero), con búsqueda opcional por placa/número/clave de acceso (el
     * destinatario no se puede buscar acá: es una lista, y la búsqueda solo soporta campos
     * planos o anidados por objeto, no por índice de array).
     */
    public function queryByProfileId(string $profileId, ?int $limit = null, ?string $cursor = null, ?string $search = null): array
    {
        return $this->queryPaginated(
            'profile_id-created_at-index',
            $profileId,
            fn($data) => GuiaRemisionEntity::fromArray($data),
            $limit,
            $cursor,
            $search,
            ['info_tributaria.secuencial', 'clave_acceso', 'info_guia_remision.placa']
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
            fn($item) => GuiaRemisionEntity::fromArray($this->marshaler->unmarshalItem($item)),
            $result['Items']
        );
    }

    public function updateStatus(string $id, string $status): ?GuiaRemisionEntity
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

    public function updateXml(string $id, ?string $xmlGenerado = null, ?string $xmlFirmado = null): ?GuiaRemisionEntity
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

    public function updateAutorizacion(string $id, string $numeroAutorizacion, string $fechaAutorizacion, string $respuestaSri): ?GuiaRemisionEntity
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

    public function updateRechazo(string $id, string $respuestaSri): ?GuiaRemisionEntity
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
