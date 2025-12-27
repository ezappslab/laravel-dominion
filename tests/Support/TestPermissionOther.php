<?php

namespace Tests\Support;

enum TestPermissionOther: string
{
    case DELETE = 'others.delete';

    case VIEW = 'others.view';
}
