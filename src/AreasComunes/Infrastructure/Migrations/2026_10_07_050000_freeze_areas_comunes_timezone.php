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
        $pgsql = DB::connection()->getDriverName() === 'pgsql';
        $schema = (string) config('database.application_schema');
        $rules = $this->table('reglas_espacio_comun');
        Schema::table($rules, function (Blueprint $table): void {
            $table->string('timezone', 100)->nullable()->after('vigente_hasta');
        });
        if ($pgsql) {
            DB::statement("UPDATE \"{$schema}\".\"reglas_espacio_comun\" rule SET timezone = building.timezone FROM \"{$schema}\".\"edificios\" building WHERE building.id = rule.edificio_id");
            DB::statement("ALTER TABLE \"{$schema}\".\"reglas_espacio_comun\" ALTER COLUMN timezone SET NOT NULL");
            Schema::table($rules, function (Blueprint $table): void {
                $table->foreign('timezone', 'reglas_espacio_timezone_fk')->references('nombre')->on($this->table('zonas_horarias'))->restrictOnDelete();
            });
            DB::unprepared(<<<SQL
                CREATE FUNCTION "{$schema}"."guard_areas_timezone"()
                RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF TG_TABLE_NAME = 'edificios' THEN
                        IF NEW.timezone IS DISTINCT FROM OLD.timezone AND EXISTS (
                            SELECT 1 FROM "{$schema}"."reglas_espacio_comun" WHERE edificio_id = OLD.id
                        ) THEN
                            RAISE EXCEPTION 'La zona horaria no puede cambiar después de registrar reglas de áreas comunes.' USING ERRCODE = '23514';
                        END IF;
                        RETURN NEW;
                    END IF;
                    IF TG_OP = 'UPDATE' AND NEW.timezone IS DISTINCT FROM OLD.timezone THEN
                        RAISE EXCEPTION 'La zona horaria de una regla es inmutable.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                \$\$;
                CREATE TRIGGER edificios_areas_timezone_guard BEFORE UPDATE OF timezone ON "{$schema}"."edificios"
                FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_areas_timezone"();
                CREATE TRIGGER reglas_areas_timezone_guard BEFORE UPDATE OF timezone ON "{$schema}"."reglas_espacio_comun"
                FOR EACH ROW EXECUTE FUNCTION "{$schema}"."guard_areas_timezone"();
                SQL);

            return;
        }

        DB::statement('UPDATE reglas_espacio_comun SET timezone = (SELECT timezone FROM edificios WHERE edificios.id = reglas_espacio_comun.edificio_id)');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reglas_areas_timezone_insert_guard BEFORE INSERT ON reglas_espacio_comun
            WHEN NEW.timezone IS NULL OR NOT EXISTS (SELECT 1 FROM zonas_horarias WHERE nombre = NEW.timezone)
            BEGIN SELECT RAISE(ABORT, 'La zona horaria de la regla no es válida.'); END;
            CREATE TRIGGER reglas_areas_timezone_update_guard BEFORE UPDATE OF timezone ON reglas_espacio_comun
            WHEN NEW.timezone IS NOT OLD.timezone
            BEGIN SELECT RAISE(ABORT, 'La zona horaria de una regla es inmutable.'); END;
            CREATE TRIGGER edificios_areas_timezone_guard BEFORE UPDATE OF timezone ON edificios
            WHEN NEW.timezone IS NOT OLD.timezone AND EXISTS (SELECT 1 FROM reglas_espacio_comun WHERE edificio_id = OLD.id)
            BEGIN SELECT RAISE(ABORT, 'La zona horaria no puede cambiar después de registrar reglas de áreas comunes.'); END;
            SQL);
    }

    public function down(): void
    {
        if (DB::table($this->table('reglas_espacio_comun'))->exists()) {
            throw new RuntimeException('No se puede retirar la zona horaria histórica mientras existan reglas de áreas comunes.');
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            $schema = (string) config('database.application_schema');
            DB::unprepared(<<<SQL
                DROP TRIGGER IF EXISTS reglas_areas_timezone_guard ON "{$schema}"."reglas_espacio_comun";
                DROP TRIGGER IF EXISTS edificios_areas_timezone_guard ON "{$schema}"."edificios";
                DROP FUNCTION IF EXISTS "{$schema}"."guard_areas_timezone"();
                SQL);
            Schema::table($this->table('reglas_espacio_comun'), function (Blueprint $table): void {
                $table->dropForeign('reglas_espacio_timezone_fk');
                $table->dropColumn('timezone');
            });

            return;
        }
        DB::statement('DROP TRIGGER IF EXISTS edificios_areas_timezone_guard');
        DB::statement('DROP TRIGGER IF EXISTS reglas_areas_timezone_update_guard');
        DB::statement('DROP TRIGGER IF EXISTS reglas_areas_timezone_insert_guard');
        DB::statement('ALTER TABLE reglas_espacio_comun DROP COLUMN timezone');
    }

    private function table(string $name): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? config('database.application_schema').'.'.$name
            : $name;
    }
};
