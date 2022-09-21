<?php

declare(strict_types=1);

namespace Evence\Bundle\SoftDeleteableExtensionBundle\Mapping;

enum Type: string
{
    case SET_NULL = 'SET_NULL';
    case SUCCESSOR = 'SUCCESSOR';
    case CASCADE = 'CASCADE';
}
