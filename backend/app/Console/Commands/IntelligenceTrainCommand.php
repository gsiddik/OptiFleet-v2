<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\Models\IntelligenceModel;
use App\Domain\Intelligence\Services\ModelRegistryService;
use App\Domain\Intelligence\Services\TrainingPipelineService;
use App\Jobs\Intelligence\TrainModelJob;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Phase 7 Section 9, 16, 50 — controlled training/activation.
 *   intelligence:train --tenant=<uuid> --target=vehicle_failure_risk
 *   intelligence:train --tenant=<uuid> --target=vehicle_failure_risk --sync
 *   intelligence:train --tenant=<uuid> --target=vehicle_failure_risk --sync --activate
 * Activation is always an explicit, separate step (never automatic on
 * training completion) — even --activate here is a deliberate operator
 * action, and still goes through ModelRegistryService's acceptance gate.
 */
class IntelligenceTrainCommand extends Command
{
    protected $signature = 'intelligence:train
        {--tenant= : Tenant ID to train a TENANT-scope model for.}
        {--target= : Model target key from config(intelligence.model_targets).}
        {--sync : Run inline instead of dispatching to the queue.}
        {--activate : If the resulting model is EVALUATED and meets its acceptance criteria, activate it.}';

    protected $description = 'Train a Phase 7 intelligence model (async by default).';

    public function handle(TrainingPipelineService $pipeline, ModelRegistryService $registry): int
    {
        $tenantId = $this->option('tenant');
        $target = $this->option('target');
        if (! $tenantId || ! $target) {
            $this->error('Both --tenant and --target are required.');

            return self::FAILURE;
        }

        if (! (bool) $this->option('sync')) {
            TrainModelJob::dispatch($tenantId, $target, IntelligenceModel::SCOPE_TENANT, 'manual');
            $this->info('Training dispatched to the queue.');

            return self::SUCCESS;
        }

        $model = $pipeline->train($tenantId, $target, IntelligenceModel::SCOPE_TENANT, 'manual');
        $this->line("Model status: {$model->status}");
        if ($model->metrics ?? null) {
            $this->line('Metrics: '.json_encode($model->metrics));
        }

        if ($this->option('activate')) {
            if ($model->status !== IntelligenceModel::STATUS_EVALUATED) {
                $this->error("Cannot activate: model status is {$model->status}, not EVALUATED.");

                return self::FAILURE;
            }
            try {
                $activated = $registry->activate($model);
                $this->info("Activated version {$activated->version}.");
            } catch (InvalidArgumentException $e) {
                $this->error('Activation refused: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
