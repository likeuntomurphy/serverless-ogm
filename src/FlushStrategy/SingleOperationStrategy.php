<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\FlushStrategy;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Likeuntomurphy\Serverless\OGM\UpdateExpressionBuilder;

readonly class SingleOperationStrategy implements FlushStrategyInterface
{
    private UpdateExpressionBuilder $expressionBuilder;

    public function __construct(
        private DynamoDbClient $client,
        private Marshaler $marshaler,
        private string $tableSuffix = '',
    ) {
        $this->expressionBuilder = new UpdateExpressionBuilder($this->marshaler);
    }

    public function execute(array $writes, array $deletes): FlushResult
    {
        $succeededWrites = [];
        $succeededDeletes = [];

        foreach ($writes as $i => $write) {
            $tableName = $write['table'].$this->tableSuffix;

            if ($write['isNew']) {
                $this->client->putItem([
                    'TableName' => $tableName,
                    'Item' => $this->marshaler->marshalItem($write['item']),
                ]);
            } else {
                $expr = $this->expressionBuilder->build($write['fieldChanges']);
                if (null !== $expr) {
                    $this->client->updateItem([
                        'TableName' => $tableName,
                        'Key' => $this->marshaler->marshalItem($write['key']),
                        ...$expr,
                    ]);
                }
            }
            $succeededWrites[] = $i;
        }

        foreach ($deletes as $i => $delete) {
            $this->client->deleteItem([
                'TableName' => $delete['table'].$this->tableSuffix,
                'Key' => $this->marshaler->marshalItem($delete['key']),
            ]);
            $succeededDeletes[] = $i;
        }

        return new FlushResult($succeededWrites, $succeededDeletes);
    }
}
