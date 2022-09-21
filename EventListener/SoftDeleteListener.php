<?php

namespace Evence\Bundle\SoftDeleteableExtensionBundle\EventListener;

use Doctrine\Common\Proxy\Proxy;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Evence\Bundle\SoftDeleteableExtensionBundle\Exception\OnSoftDeleteUnknownTypeException;
use Exception;
use Gedmo\SoftDeleteable\SoftDeleteableListener as GedmoSoftDeleteableListener;
use Mapping\Attribute\onSoftDeleteSuccessor;
use Mapping\Types;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\PropertyAccess\PropertyAccess;
use function array_key_exists;
use function class_exists;
use function count;
use function get_class;
use function in_array;
use function sprintf;

/**
 * Soft delete listener class for onSoftDelete behaviour.
 *
 * @author Sherin Bloemendaal <sherin@verbleif.com>
 *
 * @link https://verbleif.com
 * @link https://github.com/SherinBloemendaal
 */
class SoftDeleteListener
{
    private const SUPPORTED_RELATIONSHIPS = [
        ClassMetadataInfo::MANY_TO_MANY,
        ClassMetadataInfo::MANY_TO_ONE,
        ClassMetadataInfo::ONE_TO_ONE,
    ];
    use ContainerAwareTrait;

    public function preSoftDelete(LifecycleEventArgs $args)
    {
        $em = $args->getObjectManager();
        $entity = $args->getObject();

        $entityReflection = new \ReflectionObject($entity);

        $namespaces = $em->getConfiguration()
            ->getMetadataDriverImpl()
            ->getAllClassNames();

        foreach ($namespaces as $namespace) {
            $reflectionClass = new \ReflectionClass($namespace);
            if ($reflectionClass->isAbstract()) {
                continue;
            }

            $meta = $em->getClassMetadata($namespace);
            foreach ($reflectionClass->getProperties() as $property) {
                 // Skip if there are no relations, since there is nothing to cascade/set-null.
                if (!array_key_exists($property->getName(), $meta->getAssociationMappings())) {
                    continue;
                }

                $associationMapping = (object) $meta->getAssociationMapping($property->getName());
                // OneToMany is not supported
                if (!in_array($associationMapping->type, static::SUPPORTED_RELATIONSHIPS)) {
                    continue;
                }

                // If no onSoftDelete attribute is defined, skip it.
                $attributes = $property->getAttributes(\Mapping\Attribute\onSoftDelete::class);
                if (empty($attributes)) {
                    continue;
                }

                $onDelete = $attributes[0];
                $type = $onDelete->getArguments()['type'];
                if (null === ($type = Types::tryFrom($type))) {
                    throw new OnSoftDeleteUnknownTypeException($type);
                }

                $ns = null;
                $nsOriginal = $associationMapping->targetEntity;
                $nsFromRelativeToAbsolute = sprintf("%s\\%s", $entityReflection->getNamespaceName(), $associationMapping->targetEntity);
                $nsFromRoot = sprintf("\\%s", $associationMapping->targetEntity);
                if (class_exists($nsOriginal)){
                    $ns = $nsOriginal;
                } elseif (class_exists($nsFromRoot)){
                    $ns = $nsFromRoot;
                } elseif (class_exists($nsFromRelativeToAbsolute)){
                    $ns = $nsFromRelativeToAbsolute;
                } else {
                    continue;
                }

                if (!$this->checkRelationshipSupportsSetNull($type, $associationMapping)) {
                    throw new \Exception(sprintf('%s is not supported for %s relationships', $type->value, get_class($associationMapping)));
                }

                if ($associationMapping->type === ClassMetadataInfo::MANY_TO_MANY) {
                    // For ManyToMany relations, we only delete the relationship between
                    // two entities. This can be done on both side of the relation.
                    $allowMappedSide = get_class($entity) === $namespace;
                    $allowInversedSide = ($ns && $entity instanceof $ns);
                    if ($allowInversedSide) {
                        $mtmRelations = $em->getRepository($namespace)->createQueryBuilder('entity')
                            ->innerJoin(sprintf('entity.%s', $property->name), 'mtm')
                            ->addSelect('mtm')
                            ->andWhere(sprintf(':entity MEMBER OF entity.%s', $property->name))
                            ->setParameter('entity', $entity)
                            ->getQuery()
                            ->getResult();

                        foreach ($mtmRelations as $mtmRelation) {
                            try {
                                $propertyAccessor = PropertyAccess::createPropertyAccessor();
                                $collection = $propertyAccessor->getValue($mtmRelation, $property->name);
                                $collection->removeElement($entity);
                            } catch (\Exception $e) {
                                throw new \Exception(sprintf('No accessor found for %s in %s', $property->name, get_class($mtmRelation)));
                            }
                        }
                    } elseif ($allowMappedSide) {
                        try {
                            $propertyAccessor = PropertyAccess::createPropertyAccessor();
                            $collection = $propertyAccessor->getValue($entity, $property->name);
                            $collection->clear();
                            continue;
                        } catch (\Exception $e) {
                            throw new \Exception(sprintf('No accessor found for %s in %s', $property->name, get_class($entity)));
                        }
                    }

                    // We must continue because we don't want to soft delete related many-to-many, only the association.
                    continue;
                }

                $objects = $em->getRepository($namespace)->findBy(array(
                    $property->name => $entity,
                ));

                if ($objects) {
                    foreach ($objects as $object) {
                        match ($type) {
                            Types::SET_NULL => $this->processOnDeleteSetNullOperation($object, $property, $meta, $args),
                            Types::SUCCESSOR => $this->processOnDeleteSuccessorOperation($object, $property, $meta, $args),
                            Types::CASCADE => $this->processOnDeleteCascadeOperation($object, $reflectionClass, $args)
                        };
                    }
                }
            }
        }
    }

    protected function processOnDeleteSetNullOperation(
        $object,
        \ReflectionProperty $property,
        ClassMetadata $meta,
        LifecycleEventArgs $args,
    ) {
        $reflProp = $meta->getReflectionProperty($property->name);
        $oldValue = $reflProp->getValue($object);

        $reflProp->setValue($object);
        $args->getObjectManager()->persist($object);

        $args->getObjectManager()->getUnitOfWork()->propertyChanged($object, $property->name, $oldValue, null);
        $args->getObjectManager()->getUnitOfWork()->scheduleExtraUpdate($object, array(
            $property->name => array($oldValue, null),
        ));
    }

    protected function processOnDeleteSuccessorOperation(
        $object,
        \ReflectionProperty $property,
        ClassMetadata $meta,
        LifecycleEventArgs $args,
    ) {
        $reflProp = $meta->getReflectionProperty($property->name);
        $oldValue = $reflProp->getValue($object);

        $reflectionClass = new \ReflectionClass(ClassUtils::getClass($oldValue));
        $successors = [];
        foreach ($reflectionClass->getProperties() as $propertyOfOldValueObject) {
            if (!empty($propertyOfOldValueObject->getAttributes(onSoftDeleteSuccessor::class))) {
                $successors[] = $propertyOfOldValueObject;
            }
        }

        if (count($successors) > 1) {
            throw new \Exception('Only one property of deleted entity can be marked as successor.');
        } elseif (empty($successors)) {
            throw new \Exception('One property of deleted entity must be marked as successor.');
        }

        $successors[0]->setAccessible(true);

        if ($oldValue instanceof Proxy) {
            $oldValue->__load();
        }

        $newValue = $successors[0]->getValue($oldValue);
        $reflProp->setValue($object, $newValue);
        $args->getObjectManager()->persist($object);

        $args->getObjectManager()->getUnitOfWork()->propertyChanged($object, $property->name, $oldValue, $newValue);
        $args->getObjectManager()->getUnitOfWork()->scheduleExtraUpdate($object, array(
            $property->name => array($oldValue, $newValue),
        ));
    }

    protected function processOnDeleteCascadeOperation(
        $object,
        ReflectionClass $reflectionClass,
        LifecycleEventArgs $args,
    ) {
        $gedmoAttributes = $reflectionClass->getAttributes(\Gedmo\Mapping\Annotation\SoftDeleteable::class);
        $fieldName = null;
        $softDelete = false;
        if (count($gedmoAttributes) > 0) {
            $softDelete = true;
            $gedmoAttribute = $gedmoAttributes[0];
            $fieldName = $gedmoAttribute->getArguments()['fieldName'] ?? null;
        }

        if (empty($fieldName)) {
            throw new Exception('No Gedmo attribute on class or invalid fieldName specified inside Gedmo attribute.');
        }

        if ($softDelete) {
            $this->softDeleteCascade($args->getObjectManager(), $object, $fieldName);
        } else {
            $args->getObjectManager()->remove($object);
        }
    }

    protected function softDeleteCascade($em, $object, $fieldName)
    {
        $meta = $em->getClassMetadata(get_class($object));
        $reflectionProperty = $meta->getReflectionProperty($fieldName);
        $oldValue = $reflectionProperty->getValue($object);
        if ($oldValue instanceof \DateTimeInterface) {
            return;
        }

        //trigger event to check next level
        $em->getEventManager()->dispatchEvent(
            GedmoSoftDeleteableListener::PRE_SOFT_DELETE,
            new LifecycleEventArgs($object, $em)
        );

        $date = new \DateTime();
        $reflectionProperty->setValue($object, $date);

        $uow = $em->getUnitOfWork();
        $uow->propertyChanged($object, $fieldName, $oldValue, $date);
        $uow->scheduleExtraUpdate($object, [
            $fieldName => [$oldValue, $date],
        ]);

        $em->getEventManager()->dispatchEvent(
            GedmoSoftDeleteableListener::POST_SOFT_DELETE,
            new LifecycleEventArgs($object, $em)
        );
    }

    protected function checkRelationshipSupportsSetNull(Types $type, object $relationship): bool
    {
        if ($type !== Types::SET_NULL) {
            return true;
        }

        if ($relationship->type !== ClassMetadataInfo::MANY_TO_MANY) {
            return true;
        }

        return false;
    }
}
