<?php

namespace Modules\Users\Contracts;

enum UserRole: string
{
    case Admin    = 'admin';
    case Customer = 'customer';
}