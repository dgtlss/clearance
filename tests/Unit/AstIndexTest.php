<?php

namespace Dgtlss\Clearance\Tests\Unit;

use Dgtlss\Clearance\Static\AstIndex;
use Dgtlss\Clearance\Static\SurfaceAnalyzer;
use Dgtlss\Clearance\Tests\Fixtures\TraitConsumer;
use Dgtlss\Clearance\Tests\TestCase;

class AstIndexTest extends TestCase
{
    public function test_trait_imports_and_aliases_resolve_the_actual_authorization_body(): void
    {
        $index = app(AstIndex::class);
        $direct = $index->method(TraitConsumer::class, 'canEdit');
        $alias = $index->method(TraitConsumer::class, 'canReview');
        $this->assertNotNull($direct);
        $this->assertNotNull($alias);
        $this->assertSame('false', app(SurfaceAnalyzer::class)->classification($direct));
        $this->assertSame($index->fingerprint($direct), $index->fingerprint($alias));
        $this->assertNotSame($index->fingerprint(null), $index->fingerprint($direct));
    }
}
