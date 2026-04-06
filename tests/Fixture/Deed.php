<?php

namespace Likeuntomurphy\Serverless\OGM\Tests\Fixture;

use Likeuntomurphy\Serverless\OGM\Mapping\Document;
use Likeuntomurphy\Serverless\OGM\Mapping\Field;
use Likeuntomurphy\Serverless\OGM\Mapping\PartitionKey;

#[Document(table: 'deeds', pk: 'PK')]
class Deed
{
    #[PartitionKey]
    public string $id;

    #[Field]
    public string $grantee;

    #[Field]
    public ?int $acres = null;

    #[Field]
    public ?string $date = null;
}
