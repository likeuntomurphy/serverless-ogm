<?php

namespace Likeuntomurphy\Serverless\OGM\Tests;

use Aws\DynamoDb\DynamoDbClient;
use PHPUnit\Framework\TestCase;
use Likeuntomurphy\Serverless\OGM\DocumentManager;

abstract class DynamoDbTestCase extends TestCase
{
    protected DynamoDbClient $client;
    protected DocumentManager $dm;

    protected function setUp(): void
    {
        $this->client = new DynamoDbClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'endpoint' => $_ENV['DYNAMODB_ENDPOINT'] ?? 'http://dynamodb:8000',
            'credentials' => [
                'key' => 'local',
                'secret' => 'local',
            ],
        ]);

        $this->dm = new DocumentManager($this->client);
    }

    protected function ensureTable(string $tableName, string $pk, ?string $sk = null): void
    {
        $tables = $this->client->listTables()->get('TableNames');
        if (in_array($tableName, $tables, true)) {
            $this->client->deleteTable(['TableName' => $tableName]);
        }

        $schema = [
            ['AttributeName' => $pk, 'KeyType' => 'HASH'],
        ];
        $attrs = [
            ['AttributeName' => $pk, 'AttributeType' => 'S'],
        ];

        if ($sk) {
            $schema[] = ['AttributeName' => $sk, 'KeyType' => 'RANGE'];
            $attrs[] = ['AttributeName' => $sk, 'AttributeType' => 'S'];
        }

        $this->client->createTable([
            'TableName' => $tableName,
            'KeySchema' => $schema,
            'AttributeDefinitions' => $attrs,
            'BillingMode' => 'PAY_PER_REQUEST',
        ]);
    }
}
