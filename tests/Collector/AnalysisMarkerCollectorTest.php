<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Tests\Collector;

use Jensderond\PhpstanCraftcms\Collector\AnalysisMarkerCollector;
use PHPStan\Node\FileNode;
use PHPUnit\Framework\TestCase;

final class AnalysisMarkerCollectorTest extends TestCase
{
    public function test_collects_on_file_nodes(): void
    {
        self::assertSame(FileNode::class, (new AnalysisMarkerCollector)->getNodeType());
    }

    public function test_emits_a_marker_for_every_file(): void
    {
        $collector = new AnalysisMarkerCollector;

        // Scope is never touched by this collector, so a real FileNode over an
        // empty statement list is enough to prove it always emits (non-null),
        // which is what keeps CollectedDataNode rules reachable.
        $result = $collector->processNode(new FileNode([]), $this->createStub(\PHPStan\Analyser\Scope::class));

        self::assertNotNull($result);
        self::assertTrue($result);
    }
}
