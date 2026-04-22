<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Tests;

use Aws\DynamoDb\Marshaler;
use Likeuntomurphy\Serverless\OGM\UpdateExpressionBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class UpdateExpressionBuilderTest extends TestCase
{
    private UpdateExpressionBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new UpdateExpressionBuilder(new Marshaler());
    }

    public function testSetExpression(): void
    {
        $result = $this->builder->build([
            'name' => ['old' => 'Alice', 'new' => 'Bob'],
        ]);

        $this->assertNotNull($result);
        $this->assertStringContainsString('SET', $result['UpdateExpression']);
        $this->assertArrayHasKey('#f0', $result['ExpressionAttributeNames']);
        $this->assertSame('name', $result['ExpressionAttributeNames']['#f0']);
        $values = $result['ExpressionAttributeValues'] ?? null;
        $this->assertNotNull($values);
        $this->assertArrayHasKey(':v0', $values);
    }

    public function testRemoveExpression(): void
    {
        $result = $this->builder->build([
            'description' => ['old' => 'some text', 'new' => null],
        ]);

        $this->assertNotNull($result);
        $this->assertStringContainsString('REMOVE', $result['UpdateExpression']);
        $this->assertStringNotContainsString('SET', $result['UpdateExpression']);
        $this->assertArrayNotHasKey('ExpressionAttributeValues', $result);
    }

    public function testMixedSetAndRemove(): void
    {
        $result = $this->builder->build([
            'name' => ['old' => 'Alice', 'new' => 'Bob'],
            'description' => ['old' => 'text', 'new' => null],
        ]);

        $this->assertNotNull($result);
        $this->assertStringContainsString('SET', $result['UpdateExpression']);
        $this->assertStringContainsString('REMOVE', $result['UpdateExpression']);
    }

    public function testEmptyChangesReturnsNull(): void
    {
        $this->assertNull($this->builder->build([]));
    }

    public function testMultipleSetClauses(): void
    {
        $result = $this->builder->build([
            'name' => ['old' => 'Alice', 'new' => 'Bob'],
            'age' => ['old' => 30, 'new' => 31],
        ]);

        $this->assertNotNull($result);
        $this->assertCount(2, $result['ExpressionAttributeNames']);
        $values = $result['ExpressionAttributeValues'] ?? null;
        $this->assertNotNull($values);
        $this->assertCount(2, $values);
    }
}
