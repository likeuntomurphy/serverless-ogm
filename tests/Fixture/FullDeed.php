<?php

namespace Likeuntomurphy\Serverless\OGM\Tests\Fixture;

use Likeuntomurphy\Serverless\OGM\ArrayCollection;
use Likeuntomurphy\Serverless\OGM\Collection;
use Likeuntomurphy\Serverless\OGM\Mapping\Document;
use Likeuntomurphy\Serverless\OGM\Mapping\EmbedMany;
use Likeuntomurphy\Serverless\OGM\Mapping\Field;
use Likeuntomurphy\Serverless\OGM\Mapping\PartitionKey;
use Likeuntomurphy\Serverless\OGM\Mapping\Reference;
use Likeuntomurphy\Serverless\OGM\Mapping\ReferenceMany;

#[Document(table: 'full_deeds', pk: 'PK')]
class FullDeed
{
    #[PartitionKey]
    public string $id;

    #[Field]
    public ?string $grantee = null;

    #[Field]
    public ?float $acres = null;

    #[Field]
    public ?\DateTime $grantedOn = null;

    #[Field]
    public ?DeedType $type = null;

    /** @var null|list<string> */
    #[Field]
    public ?array $grantors = [];

    /** @var list<SurveyLine> */
    #[EmbedMany(targetDocument: SurveyLine::class)]
    public array $lines = [];

    #[Reference(targetDocument: FullDeed::class)]
    public ?FullDeed $origin = null;

    #[ReferenceMany(targetDocument: FullDeed::class)]
    public Collection $next;

    public function __construct()
    {
        $this->next = new ArrayCollection();
    }
}
