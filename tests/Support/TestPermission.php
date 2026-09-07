<?php

namespace Tests\Support;

enum TestPermission: string
{
    case View = 'documents.view';
    case Update = 'documents.update';
    case Delete = 'documents.delete';
}
