<?php

namespace Likeuntomurphy\Serverless\OGM\Tests\Fixture;

use Likeuntomurphy\Serverless\OGM\Mapping\Document;
use Likeuntomurphy\Serverless\OGM\Mapping\Field;
use Likeuntomurphy\Serverless\OGM\Mapping\PartitionKey;

#[Document(table: 'books', pk: 'PK')]
class Book
{
    #[PartitionKey]
    public string $id;

    #[Field]
    public string $title = '';
}
