<?php
declare(strict_types=1);

namespace PunktDe\Rebirth\Service;

/*
 * This file is part of the PunktDe.Rebirth package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 */

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Neos\Controller\Exception\NodeNotFoundException;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\ContentRepository\Domain\Factory\NodeFactory;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;

/**
 * @Flow\Scope("session")
 */
class OrphanNodeService
{
    use CreateContentContextTrait;

    /**
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var WorkspaceRepository
     * @Flow\Inject
     */
    protected $workspaceRepository;

    /**
     * @var NodeFactory
     * @Flow\Inject
     */
    protected $nodeFactory;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\InjectConfiguration(package="PunktDe.Rebirth", path="restoreTargetNodeType")
     * @var string
     */
    protected $restoreTargetNode;

    /**
     * @param string $workspaceName
     * @param string $type
     * @return ArrayCollection
     */
    public function listByWorkspace(string $workspaceName, $type = 'Neos.Neos:Document'): ArrayCollection
    {
        $nodes = $this->nodeDataByWorkspace($workspaceName);

        $nodes = $nodes->filter(function (NodeData $nodeData) use ($type) {
            return $nodeData->getNodeType()->isOfType($type);
        });

        return $nodes->map(function (NodeData $nodeData) {
            $context = $this->createContextMatchingNodeData($nodeData);
            return $this->nodeFactory->createFromNodeData($nodeData, $context);
        });
    }

    /**
     * @param NodeInterface $node
     * @param NodeInterface $target
     */
    public function restore(NodeInterface $node, NodeInterface $target): void
    {
        $node->moveInto($target);
    }


    public function getTargetNode(NodeInterface $node, $targetIdentifier = null, bool $autoCreateTargetIfNotExistent = false): NodeInterface
    {
        if ($targetIdentifier === null) {
            return $this->resolveTargetInCurrentSite($node, $autoCreateTargetIfNotExistent);
        }

        $context = $node->getContext();
        $targetNode = $context->getNodeByIdentifier($targetIdentifier);
        $this->isValidTargetNode($targetNode, $targetIdentifier);

        return $targetNode;
    }

    /**
     * @param NodeInterface $targetNode
     * @param string $expectedIdentifier
     * @return NodeInterface
     * @throws NodeNotFoundException
     */
    protected function isValidTargetNode(NodeInterface $targetNode, string $expectedIdentifier): NodeInterface
    {
        if ($targetNode === null) {
            throw new NodeNotFoundException(vsprintf('The given target node is not found (%s)', [$expectedIdentifier]), 1489566677);
        }

        if (!$targetNode->getNodeType()->isOfType('Neos.Neos:Document')) {
            throw new NodeNotFoundException(vsprintf('Target node must a of type Neos.Neos:Document (current type: %s)', [$targetNode->getNodeType()]), 1489566677);
        }

        return $targetNode;
    }

    /**
     * @param string $workspaceName
     * @return ArrayCollection
     */
    protected function nodeDataByWorkspace(string $workspaceName): ArrayCollection
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = $this->entityManager->createQueryBuilder();

        $workspaceList = [];
        /** @var Workspace $workspace */
        $workspace = $this->workspaceRepository->findByIdentifier($workspaceName);
        while ($workspace !== null) {
            $workspaceList[] = $workspace->getName();
            $workspace = $workspace->getBaseWorkspace();
        }

        return new ArrayCollection($queryBuilder
            ->select('n')
            ->from(NodeData::class, 'n')
            ->leftJoin(
                NodeData::class,
                'n2',
                Join::WITH,
                'n.parentPathHash = n2.pathHash AND n2.workspace IN (:workspaceList) AND (n.dimensionsHash = n2.dimensionsHash OR n2.dimensionsHash = :dimensionLess)'
            )
            ->where('n2.path IS NULL')
            ->andWhere($queryBuilder->expr()->not('n.path = :slash'))
            ->andWhere('n.workspace = :workspace')
            ->setParameters([
                'workspaceList' => $workspaceList,
                'slash' => '/',
                'workspace' => $workspaceName,
                'dimensionLess' => 'd751713988987e9331980363e24189ce'
            ])
            ->orderBy('n.dimensionsHash')
            ->addOrderBy('n.path')
            ->getQuery()->getResult());
    }

    protected function resolveTargetInCurrentSite(NodeInterface $node, bool $autoCreateTargetIfNotExistent): NodeInterface
    {
        $siteNode = $this->siteNode($node);
        $childNodes = $siteNode->getChildNodes($this->restoreTargetNode, 1);

        if ($childNodes === []) {
            if ($autoCreateTargetIfNotExistent) {
                return $siteNode->createNode(NodePaths::generateRandomNodeName(), $this->nodeTypeManager->getNodeType($this->restoreTargetNode));
            }

            throw new NodeNotFoundException(vsprintf('Missing restoration target node under %s', [$node->getLabel(), $node->getIdentifier()]), 1489424180);
        }

        return $childNodes[0];
    }

    protected function siteNode(NodeInterface $node): NodeInterface
    {
        /** @var ContentContext $context */
        $context = $node->getContext();
        return $context->getCurrentSiteNode();
    }
}
