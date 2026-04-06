<?php

namespace Likeuntomurphy\Serverless\OGM\Tests\Fixture;

use Likeuntomurphy\Serverless\OGM\Mapping\Document;
use Likeuntomurphy\Serverless\OGM\Mapping\Field;
use Likeuntomurphy\Serverless\OGM\Mapping\PartitionKey;

#[Document(table: 'persons', pk: 'PK')]
class Person
{
    #[PartitionKey]
    public string $id;

    #[Field]
    public string $name;
}
