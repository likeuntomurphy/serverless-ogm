<?php

namespace Likeuntomurphy\Serverless\OGM\Tests;

use Aws\DynamoDb\DynamoDbClient;
use Likeuntomurphy\Serverless\OGM\DocumentManager;
use PHPUnit\Framework\TestCase;

abstract class DynamoDbTestCase extends TestCase
{
    protected DynamoDbClient $client;
    protected DocumentManager $dm;
    protected string $tableSuffix;

    /** @var list<string> */
    private array $createdTables = [];

    protected function setUp(): void
    {
        $this->tableSuffix = '_test_'.bin2hex(random_bytes(4));

        $this->client = new DynamoDbClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'endpoint' => $_ENV['DYNAMODB_ENDPOINT'] ?? 'http://dynamodb:8000',
            'credentials' => [
                'key' => 'local',
                'secret' => 'local',
            ],
        ]);

        $this->dm = new DocumentManager($this->client, tableSuffix: $this->tableSuffix);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdTables as $table) {
            try {
                $this->client->deleteTable(['TableName' => $table]);
            } catch (\Throwable) {
            }
        }
    }

    protected function ensureTable(string $tableName, string $pk, ?string $sk = null): void
    {
        $fullName = $tableName.$this->tableSuffix;

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
            'TableName' => $fullName,
            'KeySchema' => $schema,
            'AttributeDefinitions' => $attrs,
            'BillingMode' => 'PAY_PER_REQUEST',
        ]);

        $this->createdTables[] = $fullName;
    }
}
