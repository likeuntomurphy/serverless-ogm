<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\FlushStrategy;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Likeuntomurphy\Serverless\OGM\UpdateExpressionBuilder;

readonly class BatchWriteStrategy implements FlushStrategyInterface
{
    private const int BATCH_LIMIT = 25;
    private const int MAX_RETRIES = 3;

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
        // Separate inserts (BatchWriteItem) from updates (UpdateItem)
        $inserts = [];
        $updates = [];
        foreach ($writes as $i => $write) {
            if ($write['isNew']) {
                $inserts[] = ['write' => $write, 'index' => $i];
            } else {
                $updates[] = ['write' => $write, 'index' => $i];
            }
        }

        // Build batch requests for inserts and deletes
        /** @var list<array{table: string, request: array<string, mixed>, type: 'delete'|'write', index: int}> $batchRequests */
        $batchRequests = [];

        foreach ($inserts as $entry) {
            $batchRequests[] = [
                'table' => $entry['write']['table'].$this->tableSuffix,
                'request' => ['PutRequest' => ['Item' => $this->marshaler->marshalItem($entry['write']['item'])]],
                'type' => 'write',
                'index' => $entry['index'],
            ];
        }

        foreach ($deletes as $i => $delete) {
            $batchRequests[] = [
                'table' => $delete['table'].$this->tableSuffix,
                'request' => ['DeleteRequest' => ['Key' => $this->marshaler->marshalItem($delete['key'])]],
                'type' => 'delete',
                'index' => $i,
            ];
        }

        $succeededWrites = [];
        $succeededDeletes = [];
        $failedWrites = [];
        $failedDeletes = [];

        // Execute batch (inserts + deletes)
        if ([] !== $batchRequests) {
            foreach (array_chunk($batchRequests, self::BATCH_LIMIT) as $chunk) {
                $batchResult = $this->executeBatch($chunk);

                foreach ($batchResult['succeeded'] as $req) {
                    if ('write' === $req['type']) {
                        $succeededWrites[] = $req['index'];
                    } else {
                        $succeededDeletes[] = $req['index'];
                    }
                }

                foreach ($batchResult['failed'] as $req) {
                    if ('write' === $req['type']) {
                        $failedWrites[] = $req['index'];
                    } else {
                        $failedDeletes[] = $req['index'];
                    }
                }
            }
        }

        // Execute updates individually via UpdateItem
        foreach ($updates as $entry) {
            $write = $entry['write'];
            $expr = $this->expressionBuilder->build($write['fieldChanges']);

            if (null === $expr) {
                $succeededWrites[] = $entry['index'];

                continue;
            }

            try {
                $this->client->updateItem([
                    'TableName' => $write['table'].$this->tableSuffix,
                    'Key' => $this->marshaler->marshalItem($write['key']),
                    ...$expr,
                ]);
                $succeededWrites[] = $entry['index'];
            } catch (\Throwable) {
                $failedWrites[] = $entry['index'];
            }
        }

        return new FlushResult($succeededWrites, $succeededDeletes, $failedWrites, $failedDeletes);
    }

    /**
     * @param list<array{table: string, request: array<string, mixed>, type: 'delete'|'write', index: int}> $requests
     *
     * @return array{succeeded: list<array{type: 'delete'|'write', index: int}>, failed: list<array{type: 'delete'|'write', index: int}>}
     */
    private function executeBatch(array $requests): array
    {
        $requestItems = $this->groupByTable($requests);
        $pending = $requests;
        $succeeded = [];

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; ++$attempt) {
            $result = $this->client->batchWriteItem(['RequestItems' => $requestItems]);

            /** @var array<string, list<array<string, mixed>>> $unprocessed */
            $unprocessed = $result['UnprocessedItems'] ?? [];

            if ([] === $unprocessed) {
                foreach ($pending as $req) {
                    $succeeded[] = ['type' => $req['type'], 'index' => $req['index']];
                }
                $pending = [];

                break;
            }

            $stillPending = $this->matchUnprocessed($pending, $unprocessed);
            foreach ($pending as $req) {
                if (!\in_array($req, $stillPending, true)) {
                    $succeeded[] = ['type' => $req['type'], 'index' => $req['index']];
                }
            }
            $pending = $stillPending;
            $requestItems = $unprocessed;

            if ($attempt < self::MAX_RETRIES) {
                usleep(50000 * (2 ** $attempt));
            }
        }

        $failed = [];
        foreach ($pending as $req) {
            $failed[] = ['type' => $req['type'], 'index' => $req['index']];
        }

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }

    /**
     * @param list<array{table: string, request: array<string, mixed>, type: 'delete'|'write', index: int}> $requests
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByTable(array $requests): array
    {
        $grouped = [];
        foreach ($requests as $req) {
            $grouped[$req['table']][] = $req['request'];
        }

        return $grouped;
    }

    /**
     * @param list<array{table: string, request: array<string, mixed>, type: 'delete'|'write', index: int}> $pending
     * @param array<string, list<array<string, mixed>>>                                                     $unprocessed
     *
     * @return list<array{table: string, request: array<string, mixed>, type: 'delete'|'write', index: int}>
     */
    private function matchUnprocessed(array $pending, array $unprocessed): array
    {
        $stillPending = [];
        foreach ($pending as $req) {
            foreach ($unprocessed[$req['table']] ?? [] as $unproc) {
                if ($unproc === $req['request']) {
                    $stillPending[] = $req;

                    break;
                }
            }
        }

        return $stillPending;
    }
}
