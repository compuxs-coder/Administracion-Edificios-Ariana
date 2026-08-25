<?php

namespace Src\Edificio\Infrastructure\Models\Concerns;

trait UsesApplicationSchema
{
    protected function qualifiedTable(string $table): string
    {
        if ($this->getConnection()->getDriverName() !== 'pgsql') {
            return $table;
        }

        return config('database.application_schema').'.'.$table;
    }
}
