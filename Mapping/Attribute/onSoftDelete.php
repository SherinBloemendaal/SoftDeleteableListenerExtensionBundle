<?php

namespace Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Attribute;

use Attribute;
use Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Type;

#[Attribute]
class onSoftDelete
{
    public function __construct(public Type $type)
    {
    }
}
