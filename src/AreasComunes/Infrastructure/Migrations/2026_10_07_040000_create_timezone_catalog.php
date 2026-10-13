<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        $catalog = $this->table('zonas_horarias');
        Schema::create($catalog, function (Blueprint $table): void {
            $table->string('nombre', 100)->primary();
        });
        $zones = array_values(array_unique([...timezone_identifiers_list(), 'UTC']));
        foreach (array_chunk($zones, 250) as $chunk) {
            DB::table($catalog)->insert(array_map(static fn (string $zone): array => ['nombre' => $zone], $chunk));
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            Schema::table($this->table('edificios'), function (Blueprint $table) use ($catalog): void {
                $table->foreign('timezone', 'edificios_timezone_fk')->references('nombre')->on($catalog)->restrictOnDelete();
            });

            return;
        }
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER edificios_timezone_catalog_insert_guard BEFORE INSERT ON edificios
            WHEN NOT EXISTS (SELECT 1 FROM zonas_horarias WHERE nombre = NEW.timezone)
            BEGIN SELECT RAISE(ABORT, 'La zona horaria del edificio no es válida.'); END;
            CREATE TRIGGER edificios_timezone_catalog_update_guard BEFORE UPDATE OF timezone ON edificios
            WHEN NOT EXISTS (SELECT 1 FROM zonas_horarias WHERE nombre = NEW.timezone)
            BEGIN SELECT RAISE(ABORT, 'La zona horaria del edificio no es válida.'); END;
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            Schema::table($this->table('edificios'), function (Blueprint $table): void {
                $table->dropForeign('edificios_timezone_fk');
            });
        } else {
            DB::statement('DROP TRIGGER IF EXISTS edificios_timezone_catalog_insert_guard');
            DB::statement('DROP TRIGGER IF EXISTS edificios_timezone_catalog_update_guard');
        }
        Schema::dropIfExists($this->table('zonas_horarias'));
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }
};
