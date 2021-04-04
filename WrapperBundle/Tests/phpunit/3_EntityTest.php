<?php

include_once(__DIR__.'/BaseTest.php');

class EntityTest extends BaseTest
{
    protected static $entity1RemoteId = 'kwb_test_obj_1';
    protected static $entity2RemoteId = 'kwb_test_obj_2';

    protected function getEntityByRemoteId($remoteId)
    {
        $entityManager = $this->container->get(\Kaliop\eZObjectWrapperBundle\Core\EntityManager::class);
        $testContent = $this->container->get('ezpublish.api.repository')->getContentService()->loadContentByRemoteId($remoteId);
        return $entityManager->Load($testContent);
    }

    public function testGetRepository()
    {
        $contentTypeService = $this->container->get('ezpublish.api.repository')->getContentTypeService();
        $contentTypeIdentifier = $contentTypeService->loadContentType($this->rootEntity->content()->contentInfo->contentTypeId)->identifier;

        $entityManager = $this->container->get(\Kaliop\eZObjectWrapperBundle\Core\EntityManager::class);
        $entityManager->registerClass('Test\TestRepository', $contentTypeIdentifier);

        $e2 = $entityManager->find($contentTypeIdentifier, $this->rootEntity->content()->id);

        $repo = $e2->getRepository();
        $this->assertEquals('Test\TestRepository', get_class($repo));
    }

    public function testRelationTraversingTrait()
    {
        $e2 = $this->getEntityByRemoteId(self::$entity2RemoteId);
        $e1 = $e2->getRelationFor('relation');
        $this->assertEquals(self::$entity1RemoteId, $e1->content()->contentInfo->remoteId);
        $e1s = $e2->getRelationsFor('relationlist');
        $this->assertCount(1, $e1s);
        $e1 = $e1s[0];
        $this->assertEquals(self::$entity1RemoteId, $e1->content()->contentInfo->remoteId);
    }

    /* The following throws 'LogicException: Rendering a fragment can only be done when handling a Request.' ...
    public function testRichTextConvertingTrait()
    {
        $e2 = $this->getEntityByRemoteId(self::$entity2RemoteId);
        var_dump($e2->getHtmlFor('xmltext'));
    }*/

    public function testUrlGeneratingTrait()
    {
        $e2 = $this->getEntityByRemoteId(self::$entity2RemoteId);
        $this->assertEquals("/hello-world-2", $e2->getUrl());
    }
}
