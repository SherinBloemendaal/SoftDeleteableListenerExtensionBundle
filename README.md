# SoftDeleteableListenerExtensionBundle

### This bundle only works with Symfony ^6.0 and PHP ^8.1
<hr />
Extensions to Gedmo's softDeleteable listener which has had this issue reported since 2012 : https://github.com/Atlantic18/DoctrineExtensions/issues/505.

Provides the `onSoftDelete` functionality to an association of a doctrine entity. This functionality behaves like the SQL `onDelete` function  (when the owner side is deleted). *It will prevent Doctrine errors when a reference is soft-deleted.*

## Prerequisites

**This bundle requires Symfony 6.0+ and PHP 8.1+**

## Installation

Add evence/soft-deleteable-extension-bundle to your `composer.json` file:

```
php composer.phar require "evence/soft-deleteable-extension-bundle"
```

### Register the bundle

Register bundle into config/bundles.php (Flex did it automatically):

``` php
# config/bundles.php

return [
    ...
    new Evence\Bundle\SoftDeleteableExtensionBundle\EvenceSoftDeleteableExtensionBundle(),
];
```

## Getting started

**Cascade delete the entity**

To (soft-)delete an entity when its parent record is soft-deleted:

```
#[Evence\onSoftDelete(type: Types::CASCADE)]
```

Set reference to null (instead of deleting the entity)

```
#[Evence\onSoftDelete(type: Types::SET_NULL)]
```

Replace reference by some property marked as successor (must be of same entity class)

```
#[Evence\onSoftDelete(type: Types::SUCCESSOR)]
```

## Full example

``` php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Attribute as Evence;

#[ORM\Entity(repositoryClass: 'AppBundle\Entity\AdvertisementRepository')]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt')]
class Advertisement
{
    ...

    #[Evence\onSoftDelete(type: Types::CASCADE)]
    #[ORM\ManyToOne(targetEntity: 'App\Entity\Shop')]
    #[ORM\JoinColumn(nullable: false)]
    private $shop;

    ...
}
```
