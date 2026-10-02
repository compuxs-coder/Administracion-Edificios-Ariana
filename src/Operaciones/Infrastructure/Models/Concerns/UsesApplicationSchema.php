<?php

namespace Src\Operaciones\Infrastructure\Models\Concerns;

trait UsesApplicationSchema
{
    protected function qualifiedTable(string $table): string
    {
        return $this->getConnection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$table
            : $table;
    }
}
