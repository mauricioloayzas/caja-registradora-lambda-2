<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;

/**
 * Contador atómico del secuencial SRI por (profile_id, estab, pto_emi, tipo_comprobante).
 * El incremento usa una única operación UpdateItem con ADD, que DynamoDB garantiza atómica
 * incluso bajo escrituras concurrentes: no hay ventana de lectura-luego-escritura que pueda
 * producir secuenciales duplicados o con huecos.
 */
class SecuencialRepository
{
    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private string $tableName;

    public function __construct()
    {
        $this->dbClient  = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_SECUENCIALES'];
    }

    private function buildId(string $profileId, string $estab, string $ptoEmi, string $tipoComprobante): string
    {
        return "{$profileId}#{$estab}#{$ptoEmi}#{$tipoComprobante}";
    }

    public function getNext(string $profileId, string $estab, string $ptoEmi, string $tipoComprobante, ?string $puntoEmisionId = null): string
    {
        $result = $this->dbClient->updateItem([
            'TableName'                 => $this->tableName,
            'Key'                       => $this->marshaler->marshalItem([
                'id' => $this->buildId($profileId, $estab, $ptoEmi, $tipoComprobante),
            ]),
            'UpdateExpression'          => 'SET profile_id = :profile_id, estab = :estab, pto_emi = :pto_emi, '
                . 'punto_emision_id = :punto_emision_id, tipo_comprobante = :tipo_comprobante, '
                . 'updated_at = :updated_at ADD ultimo_secuencial :inc',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([
                ':profile_id'       => $profileId,
                ':estab'            => $estab,
                ':pto_emi'          => $ptoEmi,
                ':punto_emision_id' => $puntoEmisionId,
                ':tipo_comprobante' => $tipoComprobante,
                ':updated_at'       => date('c'),
                ':inc'              => 1,
            ]),
            'ReturnValues' => 'UPDATED_NEW',
        ]);

        $ultimoSecuencial = (int) $this->marshaler->unmarshalItem($result['Attributes'])['ultimo_secuencial'];

        return str_pad((string) $ultimoSecuencial, 9, '0', STR_PAD_LEFT);
    }

    public function getCurrent(string $profileId, string $estab, string $ptoEmi, string $tipoComprobante): int
    {
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem([
                'id' => $this->buildId($profileId, $estab, $ptoEmi, $tipoComprobante),
            ]),
        ]);

        if (empty($result['Item'])) {
            return 0;
        }

        $item = $this->marshaler->unmarshalItem($result['Item']);

        return (int) ($item['ultimo_secuencial'] ?? 0);
    }

    public function getByProfileId(string $profileId): array
    {
        $result = $this->dbClient->query([
            'TableName'                 => $this->tableName,
            'IndexName'                 => 'profile_id-index',
            'KeyConditionExpression'    => 'profile_id = :profile_id',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([':profile_id' => $profileId]),
        ]);

        if (empty($result['Items'])) {
            return [];
        }

        return array_map(fn($item) => $this->marshaler->unmarshalItem($item), $result['Items']);
    }
}
