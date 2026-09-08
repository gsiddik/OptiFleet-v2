<?php

namespace App\Http\Controllers\Api\Platform\Intelligence;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Intelligence\Models\IntelligenceModel;
use App\Domain\Intelligence\Services\ModelRegistryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 7 Section 9-10, 50, 58, 63 — model registry administration.
 * Platform-scope only (Section 58: model administration is normally
 * restricted to platform/admin technical roles). Every mutating action
 * is audited.
 */
class IntelligenceModelController extends Controller
{
    public function __construct(
        private readonly ModelRegistryService $registry,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request)
    {
        $query = IntelligenceModel::query()->withoutGlobalScopes();
        foreach (['model_code', 'scope', 'tenant_id', 'status'] as $filter) {
            if ($value = $request->string($filter)->value()) {
                $query->where($filter, $value);
            }
        }

        return $this->ok($query->orderByDesc('created_at')->limit($request->integer('limit', 100))->get());
    }

    public function show(string $model)
    {
        $intelligenceModel = IntelligenceModel::query()->withoutGlobalScopes()->find($model);
        abort_if(! $intelligenceModel, 404);

        return $this->ok($intelligenceModel);
    }

    public function activate(string $model)
    {
        $intelligenceModel = IntelligenceModel::query()->withoutGlobalScopes()->find($model);
        abort_if(! $intelligenceModel, 404);

        try {
            $activated = $this->registry->activate($intelligenceModel);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return $this->message($e->getMessage(), 422);
        }

        $this->audit->log('IntelligenceModel', (string) $activated->id, 'activated', null, [
            'model_code' => $activated->model_code, 'version' => $activated->version, 'scope' => $activated->scope,
        ], $activated->tenant_id);

        return $this->ok($activated);
    }

    public function retire(string $model)
    {
        $intelligenceModel = IntelligenceModel::query()->withoutGlobalScopes()->find($model);
        abort_if(! $intelligenceModel, 404);

        $retired = $this->registry->retire($intelligenceModel);
        $this->audit->log('IntelligenceModel', (string) $retired->id, 'retired', null, [
            'model_code' => $retired->model_code, 'version' => $retired->version,
        ], $retired->tenant_id);

        return $this->ok($retired);
    }
}
