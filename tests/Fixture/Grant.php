<?php

namespace Likeuntomurphy\Serverless\OGM\Tests\Fixture;

use Likeuntomurphy\Serverless\OGM\Mapping\Document;
use Likeuntomurphy\Serverless\OGM\Mapping\Field;
use Likeuntomurphy\Serverless\OGM\Mapping\PartitionKey;
use Likeuntomurphy\Serverless\OGM\Mapping\Reference;

#[Document(table: 'grants', pk: 'PK')]
class Grant
{
    #[PartitionKey]
    public string $id;

    #[Field]
    public ?int $acres = null;

    #[Reference(targetDocument: Person::class)]
    public ?Person $grantee = null;
}
