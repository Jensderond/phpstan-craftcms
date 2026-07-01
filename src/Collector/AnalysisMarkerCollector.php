<?php

declare(strict_types=1);

namespace Jensderond\PhpstanCraftcms\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\FileNode;

/**
 * Emits one datum per analysed file so PHPStan's CollectedDataNode rules always run.
 *
 * The Twig checks (TwigActionInputRule, TwigPerformanceRule) piggyback on the
 * end-of-analysis CollectedDataNode hook. PHPStan's AnalyserResultFinalizer
 * short-circuits *before* invoking any CollectedDataNode rule when no collector
 * produced data at all (`if (count($collectedData) === 0) return;`). The only
 * other collector — ControllerActionCollector — emits nothing unless an analysed
 * file defines a concrete yii\web\Controller subclass with action* methods, so a
 * project without such a controller in scope would silently skip every Twig
 * check. This marker guarantees the collected-data set is non-empty whenever at
 * least one PHP file is analysed, keeping the Twig rules reliably reachable.
 *
 * @implements Collector<FileNode, true>
 */
final class AnalysisMarkerCollector implements Collector
{
    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @param  FileNode  $node
     */
    public function processNode(Node $node, Scope $scope): bool
    {
        return true;
    }
}
