<?php

namespace Dgtlss\Clearance\Tests\Fixtures;

class TraitConsumer
{
    use EditDecision {
        canEdit as canReview;
    }
}
