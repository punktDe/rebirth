<?php
declare(strict_types=1);

namespace PunktDe\Rebirth\Service;

/*
 * This file is part of the PunktDe.Rebirth package.
 */

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\ContentRepository\Exception\NodeExistsException;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\ContentRepository\Utility;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\Flow\Persistence\Exception;
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
     * @Flow\Inject
     * @var PersistenceManager
     */
    protected $persistenceManager;

    /**
     * @param string $workspaceName
     * @param string|null $dimensions
     * @param string $type
     * @return ArrayCollection
     */
    public function listOrphanNodes(string $workspaceName, ?string $dimensions = null, $type = 'Neos.Neos:Document'): ArrayCollection
    {
        $nodes = $this->findOrphanNodes($workspaceName, $dimensions);

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
     * @throws Exception
     */
    public function restore(NodeInterface $node, NodeInterface $target): void
    {
        $node->moveInto($target);
        $this->persistenceManager->persistAll();
    }


    /**
     * @param NodeInterface $node
     * @param string $targetIdentifier
     * @param bool $autoCreateTargetIfNotExistent
     * @return NodeInterface
     * @throws NodeNotFoundException
     */
    public function getTargetNode(NodeInterface $node, ?string $targetIdentifier = null, bool $autoCreateTargetIfNotExistent = false): NodeInterface
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
     * @param string|null $dimensions
     * @return ArrayCollection
     * @throws \JsonException
     */
    protected function findOrphanNodes(string $workspaceName, ?string $dimensions = null): ArrayCollection
    {
        $dimensionsHash = null;
        if ($dimensions !== null) {
            $dimensionsArray = json_decode($dimensions, true, 512, JSON_THROW_ON_ERROR);

            if ($dimensionsArray !== false) {
                $dimensionsHash = Utility::sortDimensionValueArrayAndReturnDimensionsHash($dimensionsArray);
            }
        }

        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = $this->entityManager->createQueryBuilder();

        $workspaceList = [];
        /** @var Workspace $workspace */
        $workspace = $this->workspaceRepository->findByIdentifier($workspaceName);

        while ($workspace !== null) {
            $workspaceList[] = $workspace->getName();
            $workspace = $workspace->getBaseWorkspace();
        }

        $query = $queryBuilder
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
            ->addOrderBy('n.path');

        if ($dimensionsHash !== null) {
            $query->andWhere('n.dimensionsHash = :dimensionsHash')->setParameter('dimensionsHash', $dimensionsHash);
        }

        return new ArrayCollection($query->getQuery()->getResult());
    }

    /**
     * @param NodeInterface $node
     * @param bool $autoCreateTargetIfNotExistent
     * @return NodeInterface
     * @throws NodeNotFoundException
     * @throws NodeExistsException
     * @throws NodeTypeNotFoundException
     * @throws Exception
     */
    protected function resolveTargetInCurrentSite(NodeInterface $node, bool $autoCreateTargetIfNotExistent): NodeInterface
    {
        $siteNode = $this->getSiteNodeOfNode($node);
        $childNodes = $siteNode->getChildNodes($this->restoreTargetNode, 1);

        if ($childNodes === []) {
            if ($autoCreateTargetIfNotExistent) {
                $targetNode = $siteNode->createNode(NodePaths::generateRandomNodeName(), $this->nodeTypeManager->getNodeType($this->restoreTargetNode));
                $targetNode->setProperty('title', 'Restored Documents');
                $this->persistenceManager->persistAll();
                return $targetNode;
            }

            throw new NodeNotFoundException(vsprintf('Missing restoration target node under %s', [$node->getLabel(), $node->getIdentifier()]), 1489424180);
        }

        return $childNodes[0];
    }

    protected function getSiteNodeOfNode(NodeInterface $node): NodeInterface
    {
        /** @var ContentContext $context */
        $context = $node->getContext();
        return $context->getCurrentSiteNode();
    }
}
