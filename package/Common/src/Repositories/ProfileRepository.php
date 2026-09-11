<?php

namespace App\Common\Repositories;

use Mauloasan\BobConstruye\DynamoDB\DynamoDbClientFactory;
use Mauloasan\BobConstruye\DynamoDB\Entities\Orchestrator\ProfileEntity;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Aws\S3\S3Client;
use Aws\Kms\KmsClient;

class ProfileRepository
{
    private DynamoDbClient $dbClient;
    private Marshaler $marshaler;
    private S3Client $s3Client;
    private KmsClient $kmsClient;
    private string $tableName;
    private string $kmsKeyArn;

    public function __construct()
    {
        $region = $_ENV['AWS_REGION'] ?? 'us-east-1';

        $this->dbClient  = DynamoDbClientFactory::create();
        $this->marshaler = new Marshaler();
        $this->tableName = $_ENV['DYNAMODB_TABLE_PROFILES'];
        $this->kmsKeyArn = $_ENV['ELECTRONIC_SIGN_KMS_KEY_ARN'] ?? '';

        $this->s3Client  = new S3Client(['region' => $region, 'version' => 'latest']);
        $this->kmsClient = new KmsClient(['region' => $region, 'version' => 'latest']);
    }

    public function get(string $id): ?ProfileEntity
    {
        $result = $this->dbClient->getItem([
            'TableName' => $this->tableName,
            'Key'       => $this->marshaler->marshalItem(['id' => $id]),
        ]);

        if (empty($result['Item'])) {
            return null;
        }

        return ProfileEntity::fromArray($this->marshaler->unmarshalItem($result['Item']));
    }

    /**
     * Descarga el .p12 del perfil desde S3 y descifra su contraseña con KMS.
     *
     * @return array{certificado_p12_base64: string, clave_certificado: string}|null
     */
    public function getCertificadoElectronico(string $profileId): ?array
    {
        $profile = $this->get($profileId);

        if (!$profile || empty($profile->url_electronic_sign) || empty($profile->electronic_sign_password)) {
            return null;
        }

        [$bucket, $key] = $this->parseS3Url($profile->url_electronic_sign);

        $objeto = $this->s3Client->getObject([
            'Bucket' => $bucket,
            'Key'    => $key,
        ]);

        $descifrado = $this->kmsClient->decrypt([
            'CiphertextBlob' => base64_decode($profile->electronic_sign_password),
            'KeyId'          => $this->kmsKeyArn,
        ]);

        return [
            'certificado_p12_base64' => base64_encode((string) $objeto['Body']),
            'clave_certificado'      => (string) $descifrado['Plaintext'],
        ];
    }

    private function parseS3Url(string $s3Url): array
    {
        $sinEsquema = substr($s3Url, strlen('s3://'));

        return explode('/', $sinEsquema, 2);
    }
}
