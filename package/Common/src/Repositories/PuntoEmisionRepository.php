<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Mauloasan\BobConstruye\DynamoDB\Entities\CajaRegistradora\PuntoEmisionEntity;
use Mauloasan\BobConstruye\DynamoDB\PaginatedProfileQueryTrait;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;

class PuntoEmisionRepository
{
    use PaginatedProfileQueryTrait;

    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient  = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_PUNTOS_EMISION'];
    }

    public function get(string $id): ?PuntoEmisionEntity
    {
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        if (empty($result['Item'])) {
            return null;
        }

        return PuntoEmisionEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));
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
            fn($item) => PuntoEmisionEntity::fromArray($this->marshaler->unmarshalItem($item)),
            $result['Items']
        );
    }

    /**
     * Versión paginada de getByProfileId(), sobre el GSI profile_id-created_at-index (más
     * reciente primero), con búsqueda opcional por estab/pto_emi/descripción.
     */
    public function queryByProfileId(string $profileId, ?int $limit = null, ?string $cursor = null, ?string $search = null): array
    {
        return $this->queryPaginated(
            'profile_id-created_at-index',
            $profileId,
            fn($data) => PuntoEmisionEntity::fromArray($data),
            $limit,
            $cursor,
            $search,
            ['estab', 'pto_emi', 'descripcion']
        );
    }

    public function findByEstabAndPtoEmi(string $profileId, string $estab, string $ptoEmi): ?PuntoEmisionEntity
    {
        $result = $this->dbClient->query([
            'TableName'                 => $this->tableName,
            'IndexName'                 => 'profile_id-estab_pto_emi-index',
            'KeyConditionExpression'    => 'profile_id = :profile_id AND estab_pto_emi = :estab_pto_emi',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                ':profile_id'    => $profileId,
                ':estab_pto_emi' => $estab . '-' . $ptoEmi,
            ]),
            'Limit' => 1,
        ]);

        if (empty($result['Items'])) {
            return null;
        }

        return PuntoEmisionEntity::fromArray($this->marshaler->unmarshalItem($result['Items'][0]));
    }

    public function create(PuntoEmisionEntity $puntoEmision): ?PuntoEmisionEntity
    {
        $this->dbClient->putItem([
            'TableName' => $this->tableName,
            'Item'      => $this->marshaler->marshalItem($puntoEmision->toArray()),
        ]);

        return $this->get($puntoEmision->id);
    }

    public function update(string $id, array $data): ?PuntoEmisionEntity
    {
        $updateExpression          = 'SET ';
        $expressionAttributeValues = [];
        $expressionAttributeNames  = [];

        foreach ($data as $key => $value) {
            if ($key === 'id' || $key === 'profile_id') {
                continue;
            }
            $updateExpression .= '#' . $key . ' = :' . $key . ', ';
            $expressionAttributeNames['#' . $key]  = $key;
            $expressionAttributeValues[':' . $key] = $value;
        }

        $updateExpression .= '#updated_at = :updated_at';
        $expressionAttributeNames['#updated_at']  = 'updated_at';
        $expressionAttributeValues[':updated_at'] = date('c');

        $this->dbClient->updateItem([
            'TableName'                 => $this->tableName,
            'Key'                       => $this->marshaler->marshalItem(['id' => $id]),
            'UpdateExpression'          => $updateExpression,
            'ExpressionAttributeValues' => $this->marshaler->marshalItem($expressionAttributeValues),
            'ExpressionAttributeNames'  => $expressionAttributeNames,
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
