<?php

namespace Likeuntomurphy\Serverless\OGM\Tests\Fixture;

enum DeedType: string
{
    case BargainAndSale = 'BS';
    case DeedOfTrust = 'DT';
    case LettersPatent = 'LP';
    case LeaseAndRelease = 'LR';
}
