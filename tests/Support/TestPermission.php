<?php

namespace Tests\Support;

enum TestPermission: string
{
    case CREATE = 'posts.create';

    case UPDATE = 'posts.update';
}
