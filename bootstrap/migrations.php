<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\Migrations\CreateAuthTables;
use App\Infrastructure\Persistence\Migrations\CreateWidgetsTable;

return [
    CreateWidgetsTable::class,
    CreateAuthTables::class,
];
