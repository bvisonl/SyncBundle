<?php

namespace NTI\SyncBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;

/**
 * SyncFailedItemStateRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class SyncFailedItemStateRepository extends EntityRepository
{
    public function findFromTimestampAndMapping($mappingName, $timestamp) {
        $qb = $this->createQueryBuilder('s');
        $qb ->innerJoin('s.mapping', 'm')
            ->andWhere('m.name = :mappingName')
            ->setParameter('mappingName', $mappingName)
            ->andWhere('s.timestamp >= :timestamp')
            ->setParameter('timestamp', $timestamp)
            ->orderBy('s.timestamp', 'asc');
        return $qb->getQuery()->getResult();
    }

    public function findByUuId($id){
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('item')
            ->from('NTISyncBundle:SyncFailedItemState', 'item')
            ->andWhere(
                $qb->expr()->eq('item.uuid', $qb->expr()->literal($id))
            );

        try {
            return $qb->getQuery()->getOneOrNullResult();
        }catch (NonUniqueResultException $e){
            return null;
        }
    }
}
