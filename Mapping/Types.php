<?php

declare(strict_types=1);

namespace Mapping;

enum Types: string
{
    case SET_NULL = 'SET_NULL';
    case SUCCESSOR = 'SUCCESSOR';
    case CASCADE = 'CASCADE';
}
