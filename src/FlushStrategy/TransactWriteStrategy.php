<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\FlushStrategy;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Likeuntomurphy\Serverless\OGM\UpdateExpressionBuilder;

readonly class TransactWriteStrategy implements FlushStrategyInterface
{
    private const int MAX_ITEMS = 100;

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
        $transactItems = [];

        foreach ($writes as $write) {
            $tableName = $write['table'].$this->tableSuffix;

            if ($write['isNew']) {
                $transactItems[] = [
                    'Put' => [
                        'TableName' => $tableName,
                        'Item' => $this->marshaler->marshalItem($write['item']),
                    ],
                ];
            } else {
                $expr = $this->expressionBuilder->build($write['fieldChanges']);
                if (null === $expr) {
                    continue;
                }

                $transactItems[] = [
                    'Update' => [
                        'TableName' => $tableName,
                        'Key' => $this->marshaler->marshalItem($write['key']),
                        ...$expr,
                    ],
                ];
            }
        }

        foreach ($deletes as $delete) {
            $transactItems[] = [
                'Delete' => [
                    'TableName' => $delete['table'].$this->tableSuffix,
                    'Key' => $this->marshaler->marshalItem($delete['key']),
                ],
            ];
        }

        if ([] === $transactItems) {
            return new FlushResult([], []);
        }

        if (\count($transactItems) > self::MAX_ITEMS) {
            throw new \OverflowException(sprintf('TransactWriteItems supports max %d items, got %d.', self::MAX_ITEMS, \count($transactItems)));
        }

        $this->client->transactWriteItems([
            'TransactItems' => $transactItems,
        ]);

        return new FlushResult(
            array_keys($writes),
            array_keys($deletes),
        );
    }
}
