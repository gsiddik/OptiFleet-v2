<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Concerns\BelongsToAnalyticsTenant;
use App\Domain\Analytics\Contracts\DatasetExtractor;
use App\Domain\Analytics\Models\EtlRun;
use App\Domain\Analytics\Services\AnalyticsRunService;
use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Eloquent\Model;
use RuntimeException;
use Tests\TestCase;

class AnalyticsEtlFoundationTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::connection('mongodb')->getDatabase()->selectCollection('analytics_etl_runs')->deleteMany([]);
        DB::connection('mongodb')->getDatabase()->selectCollection('analytics_scope_test_docs')->deleteMany([]);
        parent::tearDown();
    }

    public function test_mongodb_connection_is_configured_and_reachable(): void
    {
        $this->assertSame('mongodb', config('database.connections.mongodb.driver'));

        DB::connection('mongodb')->getClient()->getManager()->selectServer();
        $this->addToAssertionCount(1); // no exception thrown = reachable
    }

    public function test_etl_run_tracking_records_expected_metadata(): void
    {
        $tenant = $this->makeTenant();
        $this->registerFakeExtractor(new SucceedingExtractor);

        $runner = app(AnalyticsRunService::class);
        $run = $runner->runDataset($tenant->id, 'fake_ok', '2026-09-06', 'test');

        $this->assertSame(EtlRun::STATUS_COMPLETED, $run->status);
        $this->assertSame($tenant->id, $run->tenant_id);
        $this->assertSame('fake_ok', $run->job_type);
        $this->assertSame('2026-09-06', $run->business_date);
        $this->assertSame('v1', $run->version);
        $this->assertSame(5, $run->source_count);
        $this->assertSame(5, $run->processed_count);
        $this->assertSame(5, $run->inserted_count);
        $this->assertSame(0, $run->failed_count);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->completed_at);
        $this->assertIsInt($run->duration_ms);
        $this->assertSame(0, $run->retry_count);
    }

    public function test_rerunning_the_same_target_is_idempotent_not_duplicated(): void
    {
        $tenant = $this->makeTenant();
        $extractor = new SucceedingExtractor;
        $this->registerFakeExtractor($extractor);

        $runner = app(AnalyticsRunService::class);
        $run1 = $runner->runDataset($tenant->id, 'fake_ok', '2026-09-06', 'test');
        $run2 = $runner->runDataset($tenant->id, 'fake_ok', '2026-09-06', 'test');

        $this->assertSame((string) $run1->id, (string) $run2->id);

        $count = EtlRun::withoutGlobalScopes()->where([
            'tenant_id' => $tenant->id, 'job_type' => 'fake_ok', 'business_date' => '2026-09-06', 'version' => 'v1',
        ])->count();
        $this->assertSame(1, $count);
    }

    public function test_failed_run_is_tracked_and_retry_succeeds_with_incremented_retry_count(): void
    {
        $tenant = $this->makeTenant();
        $extractor = new FailsOnceExtractor;
        $this->registerFakeExtractor($extractor);

        $runner = app(AnalyticsRunService::class);

        try {
            $runner->runDataset($tenant->id, 'fake_flaky', '2026-09-06', 'test');
            $this->fail('Expected exception on first attempt.');
        } catch (RuntimeException) {
            // expected
        }

        $failed = EtlRun::withoutGlobalScopes()->where([
            'tenant_id' => $tenant->id, 'job_type' => 'fake_flaky', 'business_date' => '2026-09-06', 'version' => 'v1',
        ])->first();
        $this->assertSame(EtlRun::STATUS_FAILED, $failed->status);
        $this->assertNotEmpty($failed->error_summary);

        $retried = $runner->runDataset($tenant->id, 'fake_flaky', '2026-09-06', 'test');
        $this->assertSame(EtlRun::STATUS_COMPLETED, $retried->status);
        $this->assertSame(1, $retried->retry_count);
        $this->assertSame((string) $failed->id, (string) $retried->id);
    }

    public function test_partial_status_when_some_rows_fail_and_some_succeed(): void
    {
        $tenant = $this->makeTenant();
        $this->registerFakeExtractor(new PartialExtractor);

        $run = app(AnalyticsRunService::class)->runDataset($tenant->id, 'fake_partial', '2026-09-06', 'test');

        $this->assertSame(EtlRun::STATUS_PARTIAL, $run->status);
        $this->assertGreaterThan(0, $run->inserted_count);
        $this->assertGreaterThan(0, $run->failed_count);
    }

    public function test_one_tenants_dataset_failure_does_not_affect_another_tenants_run(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->registerFakeExtractor(new FailsForOneTenantExtractor($tenantA->id));

        $runner = app(AnalyticsRunService::class);

        try {
            $runner->runDataset($tenantA->id, 'fake_isolated', '2026-09-06', 'test');
            $this->fail('Expected tenant A to fail.');
        } catch (RuntimeException) {
            // expected
        }

        $runB = $runner->runDataset($tenantB->id, 'fake_isolated', '2026-09-06', 'test');
        $this->assertSame(EtlRun::STATUS_COMPLETED, $runB->status);

        $runA = EtlRun::withoutGlobalScopes()->where([
            'tenant_id' => $tenantA->id, 'job_type' => 'fake_isolated', 'business_date' => '2026-09-06', 'version' => 'v1',
        ])->first();
        $this->assertSame(EtlRun::STATUS_FAILED, $runA->status);
    }

    public function test_analytics_collections_enforce_cross_tenant_isolation(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        AnalyticsScopeTestDoc::query()->create(['tenant_id' => $tenantA->id, 'label' => 'a']);
        AnalyticsScopeTestDoc::query()->create(['tenant_id' => $tenantB->id, 'label' => 'b']);

        $context = app(TenantContext::class);

        $context->setTenantId($tenantA->id);
        $this->assertEqualsCanonicalizing(['a'], AnalyticsScopeTestDoc::query()->get()->pluck('label')->all());

        $context->setTenantId($tenantB->id);
        $this->assertEqualsCanonicalizing(['b'], AnalyticsScopeTestDoc::query()->get()->pluck('label')->all());

        $context->setTenantId(null);
        AnalyticsScopeTestDoc::query()->delete();
    }

    private function registerFakeExtractor(DatasetExtractor $extractor): void
    {
        $registry = new DatasetRegistry;
        $registry->register($extractor);
        $this->app->instance(DatasetRegistry::class, $registry);
    }
}

class SucceedingExtractor implements DatasetExtractor
{
    public function key(): string
    {
        return 'fake_ok';
    }

    public function label(): string
    {
        return 'Fake OK';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function run(string $tenantId, CarbonImmutable $businessDate): EtlDatasetResult
    {
        $r = new EtlDatasetResult;
        $r->sourceCount = 5;
        $r->processedCount = 5;
        $r->insertedCount = 5;

        return $r;
    }
}

class FailsOnceExtractor implements DatasetExtractor
{
    private static int $calls = 0;

    public function key(): string
    {
        return 'fake_flaky';
    }

    public function label(): string
    {
        return 'Fake Flaky';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function run(string $tenantId, CarbonImmutable $businessDate): EtlDatasetResult
    {
        self::$calls++;
        if (self::$calls === 1) {
            throw new RuntimeException('simulated transient failure');
        }

        $r = new EtlDatasetResult;
        $r->sourceCount = 3;
        $r->processedCount = 3;
        $r->insertedCount = 3;

        return $r;
    }
}

class PartialExtractor implements DatasetExtractor
{
    public function key(): string
    {
        return 'fake_partial';
    }

    public function label(): string
    {
        return 'Fake Partial';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function run(string $tenantId, CarbonImmutable $businessDate): EtlDatasetResult
    {
        $r = new EtlDatasetResult;
        $r->sourceCount = 4;
        $r->processedCount = 4;
        $r->insertedCount = 2;
        $r->recordError('row 3 invalid');
        $r->recordError('row 4 invalid');

        return $r;
    }
}

class AnalyticsScopeTestDoc extends Model
{
    use BelongsToAnalyticsTenant, HasUuids;

    protected $connection = 'mongodb';

    protected $collection = 'analytics_scope_test_docs';

    protected $fillable = ['tenant_id', 'label'];
}

class FailsForOneTenantExtractor implements DatasetExtractor
{
    public function __construct(private readonly string $failingTenantId) {}

    public function key(): string
    {
        return 'fake_isolated';
    }

    public function label(): string
    {
        return 'Fake Isolated';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function run(string $tenantId, CarbonImmutable $businessDate): EtlDatasetResult
    {
        if ($tenantId === $this->failingTenantId) {
            throw new RuntimeException('this tenant always fails');
        }

        $r = new EtlDatasetResult;
        $r->sourceCount = 2;
        $r->processedCount = 2;
        $r->insertedCount = 2;

        return $r;
    }
}
