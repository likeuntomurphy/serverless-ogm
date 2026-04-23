<?php

namespace Likeuntomurphy\Serverless\OGM\Tests\Fixture;

use Likeuntomurphy\Serverless\OGM\Collection;
use Likeuntomurphy\Serverless\OGM\Mapping\Document;
use Likeuntomurphy\Serverless\OGM\Mapping\Field;
use Likeuntomurphy\Serverless\OGM\Mapping\PartitionKey;
use Likeuntomurphy\Serverless\OGM\Mapping\ReferenceMany;

#[Document(table: 'authors', pk: 'PK')]
class Author
{
    #[PartitionKey]
    public string $id;

    #[Field]
    public string $name = '';

    #[ReferenceMany(targetDocument: Book::class, adjacencyTable: 'author_books', adjacencyPk: 'authorId', adjacencySk: 'bookId')]
    public Collection $books;
}
