<?php

namespace Kaliop\eZObjectWrapperBundle\Repository;

use Exception;
use eZ\Publish\API\Repository\Exceptions\InvalidArgumentException;
use eZ\Publish\API\Repository\Exceptions\NotFoundException;
use eZ\Publish\API\Repository\Exceptions\UnauthorizedException;
use Kaliop\eZObjectWrapperBundle\Core\EntityInterface;
use Kaliop\eZObjectWrapperBundle\Core\Repository;
use eZ\Publish\API\Repository\Values\Content\LocationQuery;
use eZ\Publish\API\Repository\Values\Content\Query;
use eZ\Publish\API\Repository\Values\Content\Query\Criterion;
use eZ\Publish\API\Repository\Values\Content\Query\SortClause;
use eZ\Publish\API\Repository\Repository as eZRepository;

abstract class AbstractRepository extends Repository
{
    /**
     * Max 32-bit Integer (limited by SOLR)
     */
    const SOLR_INT_MAX = 2147483647;

    /**
     * Build query to find all objects of a current type.
     *
     * @param array $sortClauses
     *
     * @return Query
     */
    public function getFindAllQuery(array $sortClauses = []): Query
    {
        $query = new Query();
        $query->filter = new Criterion\LogicalAnd([
            new Criterion\ContentTypeIdentifier($this->contentTypeIdentifier),
            new Criterion\Subtree('/1/2/'),
            new Criterion\Visibility(Criterion\Visibility::VISIBLE),
        ]);
        $query->limit = self::SOLR_INT_MAX - 1;
        $query->offset = 0;
        $query->sortClauses = $sortClauses;

        return $query;
    }

    /**
     * Find all objects of a current type.
     *
     * @param array $sortClauses
     * @return EntityInterface[]
     *
     * @throws InvalidArgumentException
     */
    public function findAll(array $sortClauses = []): array
    {
        $query = $this->getFindAllQuery($sortClauses);
        return $this->loadEntitiesFromSearchResults(
            $this->getSearchService()->findContent($query)
        );
    }

    /**
     * Build query to find all children of a current type for a given location. If non sort clauses passed, sort by parent location sort clause.
     *
     * @param int $parentLocationId
     * @param int $limit
     * @param int $offset
     * @param SortClause[] $sortClauses
     *
     * @return LocationQuery
     *
     * @throws UnauthorizedException
     * @throws NotFoundException
     * @throws \Exception
     */
    public function getFindByParentLocationQuery(int $parentLocationId, ?int $limit = null, int $offset = 0, array $sortClauses = []): LocationQuery
    {
        $parentLocation = $this->repository->sudo(
            function () use ($parentLocationId) {
                return $this->getLocationService()->loadLocation($parentLocationId);
            }
        );

        $query = new LocationQuery();
        $query->filter = new Criterion\LogicalAnd([
            new Criterion\ContentTypeIdentifier($this->contentTypeIdentifier),
            new Criterion\ParentLocationId($parentLocationId),
            new Criterion\Visibility(Criterion\Visibility::VISIBLE),
        ]);

        $query->limit = $limit ? $limit : self::SOLR_INT_MAX - 1;
        $query->offset = $offset;
        $query->sortClauses = $sortClauses ? $sortClauses : $parentLocation->getSortClauses();

        return $query;
    }

    /**
     * Find all children of a current type for a given location. If non sort clauses passed, sort by parent location sort clause.
     *
     * @param int $parentLocationId
     * @param int $limit
     * @param int $offset
     * @param SortClause[] $sortClauses
     *
     * @return EntityInterface[]
     *
     * @throws InvalidArgumentException
     * @throws NotFoundException
     * @throws UnauthorizedException
     */
    public function findByParentLocation(int $parentLocationId, ?int $limit = null, int $offset = 0, array $sortClauses = []): array
    {
        $query = $this->getFindByParentLocationQuery($parentLocationId, $limit, $offset, $sortClauses);
        return $this->loadEntitiesFromSearchResults(
            $this->getSearchService()->findLocations($query)
        );
    }

    /**
     * Build query to find all content objects of a current type in a given subtree.
     *
     * @param string|string[] $subtree
     * @param int $limit
     * @param int $offset
     * @param SortClause[] $sortClauses
     *
     * @return LocationQuery
     */
    public function getFindBySubtreeQuery($subtree, ?int $limit = null, int $offset = 0, array $sortClauses = []): LocationQuery
    {
        $query = new LocationQuery();
        $query->filter = new Criterion\LogicalAnd([
            new Criterion\ContentTypeIdentifier($this->contentTypeIdentifier),
            new Criterion\Subtree($subtree),
            new Criterion\Visibility(Criterion\Visibility::VISIBLE),
        ]);

        $query->limit = $limit ? $limit : self::SOLR_INT_MAX - 1;
        $query->offset = $offset;

        if ($sortClauses) {
            $query->sortClauses = $sortClauses;
        }

        return $query;
    }

    /**
     * Find all content objects of a current type in a given subtree.
     *
     * @param string|string[] $subtree
     * @param int $limit
     * @param int $offset
     * @param SortClause[] $sortClauses
     *
     * @return EntityInterface[]
     *
     * @throws InvalidArgumentException
     */
    public function findBySubtree($subtree, ?int $limit = null, int $offset = 0, array $sortClauses = []): array
    {
        $query = $this->getFindBySubtreeQuery($subtree, $limit, $offset, $sortClauses);
        return $this->loadEntitiesFromSearchResults(
            $this->getSearchService()->findLocations($query)
        );
    }

    /**
     * Creates content object under given location.
     *
     * @param int $parentLocationId
     * @param array $fields
     * @param string|null $remoteId
     * @param bool $visible
     *
     * @return EntityInterface|null
     */
    public function create(int $parentLocationId, array $fields, string $remoteId = null, bool $visible = true): ?EntityInterface
    {
        try {
            return $this->repository->sudo(function (eZRepository $repository) use ($parentLocationId, $fields, $remoteId, $visible) {
                $contentTypeService = $repository->getContentTypeService();
                $contentService = $repository->getContentService();

                $contentType = $contentTypeService->loadContentTypeByIdentifier($this->contentTypeIdentifier);
                $contentCreateStruct = $contentService->newContentCreateStruct($contentType, 'pol-PL');
                if ($remoteId) {
                    $contentCreateStruct->remoteId = $remoteId;
                }

                foreach ($fields as $field => $value) {
                    $contentCreateStruct->setField($field, $value);
                }

                $locationCreateStruct = $repository->getLocationService()->newLocationCreateStruct($parentLocationId);
                $locationCreateStruct->hidden = !$visible;
                $draft = $contentService->createContent($contentCreateStruct, [$locationCreateStruct]);

                return $this->loadEntityFromContent(
                    $contentService->publishVersion($draft->versionInfo)
                );
            });
        } catch (Exception $e) {
            $this->error('Cannot create content: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param EntityInterface $entity
     * @param array $fields
     *
     * @return EntityInterface|null
     */
    public function update(EntityInterface $entity, array $fields): ?EntityInterface
    {
        try {
            return $this->repository->sudo(function (eZRepository $repository) use ($entity, $fields) {
                $update = false;
                foreach ($fields as $field => $value) {
                    if (trim((string)$entity->content()->getFieldValue($field)) !== trim($value)) {
                        $update = true;
                    }
                }

                if ($update) {
                    $contentService = $repository->getContentService();
                    $contentDraft = $contentService->createContentDraft($entity->content()->contentInfo);

                    $contentUpdateStruct = $contentService->newContentUpdateStruct();
                    $contentUpdateStruct->initialLanguageCode = 'pol-PL';
                    foreach ($fields as $field => $value) {
                        $contentUpdateStruct->setField($field, $value);
                    }

                    $contentDraft = $contentService->updateContent($contentDraft->versionInfo, $contentUpdateStruct);
                    return $this->loadEntityFromContent(
                        $contentService->publishVersion($contentDraft->versionInfo)
                    );
                } else {
                    return $entity;
                }
            });
        } catch (Exception $e) {
            $this->error('Cannot create content: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param EntityInterface $entity
     * @param string $remoteId
     */
    public function updateRemoteId(EntityInterface $entity, string $remoteId): void
    {
        try {
            $this->repository->sudo(function (eZRepository $repository) use ($entity, $remoteId) {
                $contentService = $repository->getContentService();
                $contentMetaDataUpdateStruct = $contentService->newContentMetadataUpdateStruct();
                $contentMetaDataUpdateStruct->remoteId = $remoteId;

                $contentService->updateContentMetadata($entity->content()->contentInfo, $contentMetaDataUpdateStruct);
            });
        } catch (Exception $e) {
            $this->error('Cannot update content remote id: ' . $e->getMessage());
        }
    }
}
