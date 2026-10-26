<?php

namespace Src\Gobierno\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Src\Edificio\Application\Services\AccesoEdificioService;
use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Gobierno\Application\Actions\GetCentroAuditoriaAction;
use Src\Gobierno\Application\Actions\SolicitarExportacionAuditoriaAction;
use Src\Gobierno\Domain\CatalogoAuditoria;
use Src\Gobierno\Domain\Contracts\ExportacionAuditoriaRepositoryInterface;
use Src\Gobierno\Infrastructure\Requests\StoreExportacionAuditoriaRequest;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class AuditoriaWebController extends Controller
{
    private const AUDIT_VISIBILITY_PERMISSIONS = [
        'gobierno.ver', 'gobierno.detalles.ver', 'miembros.ver', 'operaciones.ver',
        'mantenimiento_preventivo.ver', 'areas_comunes.ver', 'reservas_areas_comunes.ver',
        'comunicados.ver',
    ];

    public function __construct(
        private readonly AccesoEdificioService $access,
        private readonly GetCentroAuditoriaAction $getAuditCenter,
        private readonly SolicitarExportacionAuditoriaAction $requestExport,
        private readonly ExportacionAuditoriaRepositoryInterface $exports,
    ) {}

    public function index(Request $request): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        $permissionMap = $this->access->permissionMap($userId);
        $viewBuildingIds = array_keys(array_filter(
            $permissionMap,
            static fn (array $permissions): bool => in_array(PermisoEdificio::GOBIERNO_VER->value, $permissions, true),
        ));
        $partialData = explode(',', (string) $request->header('X-Inertia-Partial-Data'));
        $isScopePoll = $request->header('X-Inertia-Partial-Component') === 'Gobierno/Auditoria/index'
            && in_array('alcanceVigente', $partialData, true);
        abort_unless($viewBuildingIds !== [] || $isScopePoll, 403);

        $filters = $request->validate([
            'edificios' => ['nullable', 'array', 'min:1'],
            'edificios.*' => ['required', 'uuid', 'distinct'],
            'fuente' => ['nullable', Rule::in(CatalogoAuditoria::SOURCES)],
            'tipo' => ['nullable', Rule::in(CatalogoAuditoria::EVENT_TYPES)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $result = null;
        $resolve = function () use (&$result, $userId, $filters): array {
            return $result ??= $this->getAuditCenter->execute($userId, $filters);
        };
        $selectedBuildingIds = $filters['edificios'] ?? $viewBuildingIds;
        $scopeIsCurrent = $selectedBuildingIds !== [] && array_diff($selectedBuildingIds, $viewBuildingIds) === [];
        $exportBuildingIds = array_keys(array_filter(
            $permissionMap,
            static fn (array $permissions): bool => in_array(PermisoEdificio::GOBIERNO_EXPORTAR->value, $permissions, true),
        ));
        $scopeFingerprint = $this->scopeFingerprint($permissionMap, $selectedBuildingIds);

        return Inertia::render('Gobierno/Auditoria/index', [
            'eventos' => static function () use ($resolve): array {
                $events = $resolve()['eventos'];

                return [
                    'data' => $events['items'],
                    'meta' => [
                        'total' => $events['total'],
                        'currentPage' => $events['currentPage'],
                        'lastPage' => $events['lastPage'],
                        'perPage' => $events['perPage'],
                    ],
                ];
            },
            'edificios' => static fn (): array => $resolve()['edificios'],
            'fuentes' => static fn (): array => $resolve()['fuentes'],
            'politicaRetencion' => static fn (): array => $resolve()['politicaRetencion'],
            'filters' => static function () use ($filters, $resolve): array {
                return [...$filters, 'edificios' => $resolve()['eventos']['selectedBuildingIds']];
            },
            'exportaciones' => fn (): array => $this->exports->recentForUser($userId),
            'alcanceVigente' => $scopeIsCurrent,
            'huellaAlcance' => $scopeFingerprint,
            'puedeExportar' => $scopeIsCurrent && array_diff($selectedBuildingIds, $exportBuildingIds) === [],
            'limitesExportacion' => [
                'maxRangeDays' => (int) config('gobierno.exports.max_range_days'),
                'csvMaxRows' => (int) config('gobierno.exports.csv_max_rows'),
                'pdfMaxRows' => (int) config('gobierno.exports.pdf_max_rows'),
                'ttlHours' => (int) config('gobierno.exports.ttl_hours'),
            ],
        ]);
    }

    public function storeExport(StoreExportacionAuditoriaRequest $request): RedirectResponse
    {
        $result = $this->requestExport->execute(
            (string) $request->user()->getAuthIdentifier(),
            $request->validated(),
        );

        return back()->with('success', $result['created']
            ? 'La exportación fue encolada y se procesará en segundo plano.'
            : 'La solicitud ya estaba registrada; se reutilizó la exportación existente.');
    }

    public function downloadExport(Request $request, string $exportacion): BinaryFileResponse
    {
        $file = $this->exports->downloadForUser(
            (string) $request->user()->getAuthIdentifier(),
            $exportacion,
        );

        $response = response()->download($file['localPath'], $file['name'], [
            'Content-Type' => $file['mimeType'],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ])->deleteFileAfterSend(true);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /** @param array<string, list<string>> $permissionMap @param list<string> $buildingIds */
    private function scopeFingerprint(array $permissionMap, array $buildingIds): string
    {
        sort($buildingIds);
        $scope = [];
        foreach ($buildingIds as $buildingId) {
            $permissions = array_values(array_intersect(
                $permissionMap[$buildingId] ?? [],
                self::AUDIT_VISIBILITY_PERMISSIONS,
            ));
            sort($permissions);
            $scope[$buildingId] = $permissions;
        }

        return hash_hmac('sha256', json_encode($scope, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
