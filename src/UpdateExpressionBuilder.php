<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM;

use Aws\DynamoDb\Marshaler;

class UpdateExpressionBuilder
{
    public function __construct(
        private readonly Marshaler $marshaler,
    ) {
    }

    /**
     * @param array<string, array{old: mixed, new: mixed}> $fieldChanges
     *
     * @return null|array{UpdateExpression: string, ExpressionAttributeNames: array<string, string>, ExpressionAttributeValues?: array<string, mixed>}
     */
    public function build(array $fieldChanges): ?array
    {
        $setClauses = [];
        $removeClauses = [];
        $names = [];
        $values = [];
        $i = 0;

        foreach ($fieldChanges as $attributeName => $change) {
            $nameAlias = '#f'.$i;
            $names[$nameAlias] = $attributeName;

            if (null === $change['new']) {
                $removeClauses[] = $nameAlias;
            } else {
                $valueAlias = ':v'.$i;
                $setClauses[] = $nameAlias.' = '.$valueAlias;
                $values[$valueAlias] = $this->marshaler->marshalValue($change['new']);
            }
            ++$i;
        }

        if ([] === $setClauses && [] === $removeClauses) {
            return null;
        }

        $expression = '';
        if ([] !== $setClauses) {
            $expression .= 'SET '.implode(', ', $setClauses);
        }
        if ([] !== $removeClauses) {
            $expression .= ('' !== $expression ? ' ' : '').'REMOVE '.implode(', ', $removeClauses);
        }

        $result = [
            'UpdateExpression' => $expression,
            'ExpressionAttributeNames' => $names,
        ];

        if ([] !== $values) {
            $result['ExpressionAttributeValues'] = $values;
        }

        return $result;
    }
}
