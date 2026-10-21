<?php

namespace Src\Gobierno\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Src\Edificio\Application\Services\AccesoEdificioService;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Gobierno\Application\Actions\GetCentroAuditoriaAction;

final class AuditoriaWebController extends Controller
{
    private const EVENT_TYPES = [
        'migracion', 'membresia_creada', 'invitacion_creada', 'invitacion_aceptada',
        'invitacion_revocada', 'roles_actualizados', 'membresia_revocada',
        'creacion', 'cambio_datos', 'cambio_estado', 'asignacion', 'reasignacion',
        'cancelacion', 'reapertura', 'actuacion_manual', 'evidencia', 'activacion',
        'pausa', 'generacion', 'bloqueo', 'reintento', 'omision', 'espacio_creado',
        'espacio_actualizado', 'espacio_activado', 'espacio_inactivado', 'regla_creada',
        'reserva_creada', 'reserva_aprobada', 'reserva_rechazada', 'reserva_cancelada',
        'comunicado_creado', 'comunicado_actualizado', 'comunicado_publicado',
        'comunicado_archivado', 'documento_agregado', 'documento_retirado',
        'entrega_reintentada',
    ];

    public function __construct(
        private readonly AccesoEdificioService $access,
        private readonly GetCentroAuditoriaAction $getAuditCenter,
    ) {}

    public function index(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        abort_unless($this->access->allowsAny($userId, PermisoEdificio::GOBIERNO_VER), 403);

        $filters = $request->validate([
            'edificios' => ['nullable', 'array', 'min:1'],
            'edificios.*' => ['required', 'uuid', 'distinct'],
            'fuente' => ['nullable', Rule::in(['accesos', 'operaciones', 'mantenimiento', 'areas_comunes', 'comunicacion'])],
            'tipo' => ['nullable', Rule::in(self::EVENT_TYPES)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $result = $this->getAuditCenter->execute($userId, $filters);
        $filters['edificios'] = $result['eventos']['selectedBuildingIds'];

        return Inertia::render('Gobierno/Auditoria/index', [
            'eventos' => [
                'data' => $result['eventos']['items'],
                'meta' => [
                    'total' => $result['eventos']['total'],
                    'currentPage' => $result['eventos']['currentPage'],
                    'lastPage' => $result['eventos']['lastPage'],
                    'perPage' => $result['eventos']['perPage'],
                ],
            ],
            'edificios' => $result['edificios'],
            'fuentes' => $result['fuentes'],
            'politicaRetencion' => $result['politicaRetencion'],
            'filters' => $filters,
        ]);
    }
}
