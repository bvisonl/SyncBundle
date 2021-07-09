<?php

namespace NTI\SyncBundle\EventSubscriber;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\EventSubscriber;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use NTI\SyncBundle\Annotations\SyncEntity;
use NTI\SyncBundle\Annotations\SyncIgnore;
use NTI\SyncBundle\Annotations\SyncParent;
use NTI\SyncBundle\Entity\SyncMapping;
use NTI\SyncBundle\Entity\SyncState;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DoctrineEventSubscriber implements EventSubscriber
{
    private $container;
    private $syncService;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->syncService = $this->container->get('nti.sync');
    }

    public function getSubscribedEvents()
    {
        return array(
            'onFlush',
        );
    }

    public function onFlush(OnFlushEventArgs $args)
    {
        $em = $args->getEntityManager();
        $uow = $em->getUnitOfWork();


        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->processEntity($em, $entity);
        }

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->processEntity($em, $entity);
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $this->processEntity($em, $entity, true);
            $getIdentifier = $this->container->getParameter('nti.sync.deletes.identifier_getter');
            $this->container->get('nti.sync')->addToDeleteSyncState(ClassUtils::getClass($entity), $entity->$getIdentifier());
        }


        /** @var PersistentCollection $collectionUpdate */
        foreach ($uow->getScheduledCollectionUpdates() as $collectionUpdate) {
            foreach ($collectionUpdate as $entity) {
                $this->processEntity($em, $entity);
            }
        }

        /** @var PersistentCollection $collectionDeletion */
        foreach ($uow->getScheduledCollectionDeletions() as $collectionDeletion) {
            foreach ($collectionDeletion as $entity) {
                if (!is_object($entity)) {
                    return;
                }
                $this->processEntity($em, $entity, true);
                $getIdentifier = $this->container->getParameter('nti.sync.deletes.identifier_getter');
                $this->container->get('nti.sync')->addToDeleteSyncState(ClassUtils::getClass($entity), $entity->$getIdentifier());
            }
        }
    }

    private function processEntity(EntityManagerInterface $em, $entity, $deleting = false)
    {
        $uow = $em->getUnitOfWork();

        if (!is_object($entity)) {
            return;
        }

        $ignoreProperties = $this->container->getParameter('nti.sync.last_timestamp.ignore_properties');
        $changes = $uow->getEntityChangeSet($entity);
        $totalPropertiesChanged = count($changes);

        foreach (array_keys($changes) as $propertyChanged) {
            foreach ($ignoreProperties as $ignoreProperty) {
                if ($ignoreProperty["class"] == ClassUtils::getClass($entity) && $ignoreProperty["property"] == $propertyChanged) {
                    $totalPropertiesChanged --;
                }
            }
        }

        if ($totalPropertiesChanged <= 0) {
            return;
        }


        $reflection = new \ReflectionClass(ClassUtils::getClass($entity));
        $annotationReader = new AnnotationReader();
        $syncEntityAnnotation = $annotationReader->getClassAnnotation($reflection, SyncEntity::class);
        // Check if the entity should be synchronized
        if (!$syncEntityAnnotation) {
            return;
        }

        $uow = $em->getUnitOfWork();
        $timestamp = time();

        // Update the mapping's sync state if exists
        $mapping = $em->getRepository(SyncMapping::class)->findOneBy(array("class" => ClassUtils::getClass($entity)));
        if ($mapping) {
            $syncState = $em->getRepository(SyncState::class)->findOneBy(array("mapping" => $mapping));
            if (!$syncState) {
                $syncState = new SyncState();
                $syncState->setMapping($mapping);
                $em->persist($syncState);
            }
            $syncState->setTimestamp($timestamp);
            if ($uow->getEntityState($syncState) == UnitOfWork::STATE_MANAGED) {
                if ($syncState->getId()) {
                    $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(SyncState::class), $syncState);
                } else {
                    $uow->computeChangeSet($em->getClassMetadata(SyncState::class), $syncState);
                }
            }
        }

        // Check if this class itself has a lastTimestamp
        if (!$deleting && method_exists($entity, 'setLastTimestamp')) {
            $entity->setLastTimestamp($timestamp);
            if ($uow->getEntityState($entity) == UnitOfWork::STATE_MANAGED) {
                $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(ClassUtils::getClass($entity)), $entity);
            }
        }

        // Notify relationships
        /** @var \ReflectionProperty $property */

        foreach ($reflection->getProperties() as $property) {
            if ($property == null) {
                continue;
            }
            if (null !== ($annotation = $annotationReader->getPropertyAnnotation($property, SyncIgnore::class))) {
                continue;
            }
            /** @var SyncParent $annotation */
            if (null !== ($annotation = $annotationReader->getPropertyAnnotation($property, SyncParent::class))) {
                $getter = $annotation->getter;
                $parent = $entity->$getter();
                if ($parent == null) {
                    continue;
                }
                // Using ClassUtils as $parent is actually a Proxy of the class
                $reflrectionParent = new \ReflectionClass(ClassUtils::getClass($parent));
                $syncParentAnnotation = $annotationReader->getClassAnnotation($reflrectionParent, SyncEntity::class);
                if (!$syncParentAnnotation) {
                    continue;
                }
                $this->processEntity($em, $parent);
            }
        }
    }
}
