<?php
declare(strict_types=1);

namespace PunktDe\Rebirth\Command;

/*
 * This file is part of the PunktDe.Rebirth package.
 */

use Closure;
use Doctrine\Common\Collections\ArrayCollection;
use PunktDe\Rebirth\Service\OrphanNodeService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Neos\Controller\Exception\NodeNotFoundException;
use Neos\ContentRepository\Domain\Model\NodeInterface;

class RebirthCommandController extends CommandController
{
    /**
     * @var OrphanNodeService
     * @Flow\Inject
     */
    protected $orphanNodeService;

    /**
     * List orphan documents
     *
     * @param string $workspace The workspace to use
     * @param string|null $dimensions The dimension combination as json representation, defaults to all dimensions
     * @param string $type The supertype of the nods to search
     */
    public function listCommand(string $workspace = 'live', ?string $dimensions = null, string $type = 'Neos.Neos:Document'): void
    {
        $nodes = $this->orphanNodeService->listOrphanNodes($workspace, $dimensions, $type);

        $this->output->outputTable(
            array_map(static function (array $nodeInfo) {
                $nodeInfo['info'] = sprintf('Label: %s%sPath: %s' . PHP_EOL, $nodeInfo['label'], PHP_EOL, $nodeInfo['path']);
                $nodeInfo['dimension'] = str_replace(',', PHP_EOL, $nodeInfo['dimension']);
                unset($nodeInfo['label'], $nodeInfo['path']);
                return $nodeInfo;
            }, $this->convertNodesToNodeInfo($nodes)),
            ['Site', 'Dimension', 'Identifier', 'Node Type', 'Info']
        );

        if (count($nodes)) {
            $this->outputLine('<b>Found nodes:</b> %d', [count($nodes)]);
        } else {
            $this->outputLine('<b>No orphaned document nodes</b>');
        }
    }

    /**
     * Prune orphan documents
     *
     * @param string $workspace The workspace to use
     * @param string|null $dimensions The dimension combination as json representation, defaults to all dimensions
     * @param string $type The supertype of the nods to search
     */
    public function pruneAllCommand(string $workspace = 'live', ?string $dimensions = null, string $type = 'Neos.Neos:Document'): void
    {
        $this->command(function (NodeInterface $node) {
            $this->output->outputLine('%s <comment>%s</comment> (%s) in <b>%s</b>', [$node->getIdentifier(), $node->getLabel(), $node->getNodeType(), $node->getPath()]);
            $node->remove();
            $this->outputLine('  <info>Done, node removed</info>');
        }, $workspace, $dimensions, $type, false);
    }

    /**
     * Restore orphan documents
     *
     * @param string $workspace
     * @param string|null $dimensions The dimension combination as json representation, defaults to all dimensions
     * @param string $type A superType of documents to restore
     * @param string|null $target The identifier of the restore target
     * @param bool $autoCreateTarget Automatically create the trash document if it does not exist.
     */
    public function restoreAllCommand(string $workspace = 'live', ?string $dimensions = null, string $type = 'Neos.Neos:Document', string $target = null, bool $autoCreateTarget = false): void
    {
        $this->command(function (NodeInterface $node, $restore, $targetIdentifier) use ($autoCreateTarget) {
            $nodeInfo = $this->convertNodeToNodeInfo($node);

            $this->output->outputLine('%s %s %s <comment>%s</comment> (%s) in <b>%s</b>', $nodeInfo);

            if ($restore) {
                try {
                    $target = $this->orphanNodeService->getTargetNode($node, $targetIdentifier, $autoCreateTarget);
                    $this->outputLine('  <info>Restore to %s: %s</info>', [$target->getLabel(), $target]);
                    $this->orphanNodeService->restore($node, $target);
                    $this->outputLine('  <info>Done, check your node at "%s"</info>', [$node]);
                } catch (NodeNotFoundException $exception) {
                    $this->outputLine('  <error>Missing restoration target for the current node</error>');
                    return;
                } catch (\Exception $e) {
                    $this->outputLine('  <error>Could not restore the node. Skipping Step. Exited with error:</error>');
                    $this->outputLine(sprintf('  <error>%s (Error Code: %d)</error>', $e->getMessage(), $e->getCode()));
                    return;
                }
            }
        }, $workspace, $dimensions, $type, true, $target);
    }

    /**
     * @param Closure $func
     * @param string $workspace
     * @param string|null $dimensions
     * @param string $type
     * @param bool $restore
     * @param string|null $targetIdentifier
     */
    protected function command(Closure $func, string $workspace, ?string $dimensions, string $type, bool $restore = false, string $targetIdentifier = null): void
    {
        $nodes = $this->orphanNodeService->listOrphanNodes($workspace, $dimensions, $type);
        $nodes->map(function (NodeInterface $node) use ($func, $restore, $targetIdentifier) {
            $func($node, $restore, $targetIdentifier);
        });

        if ($nodes->count()) {
            $this->outputLine('<b>Processed nodes:</b> %d', [$nodes->count()]);
        } else {
            $this->outputLine('<b>No orphaned document nodes</b>');
        }
    }

    protected function convertNodesToNodeInfo(ArrayCollection $nodes): array
    {
        return array_map([$this, 'convertNodeToNodeInfo'], $nodes->toArray());
    }

    protected function convertNodeToNodeInfo(NodeInterface $node): array
    {
        return [
            'site' => $node->getContext()->getCurrentSite()->getName(),
            'dimension' => str_replace(['{', '}', '[', ']', '"'], '', json_encode($node->getContext()->getDimensions(), JSON_THROW_ON_ERROR)),
            'identifier' => $node->getIdentifier(),
            'nodeType' => $node->getNodeType(),
            'label' => $node->getLabel(),
            'path' => $node->getPath(),
        ];
    }
}
