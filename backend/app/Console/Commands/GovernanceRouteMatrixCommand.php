<?php

namespace App\Console\Commands;

use App\Domain\Governance\RouteAuthorizationMatrix;
use Illuminate\Console\Command;

class GovernanceRouteMatrixCommand extends Command
{
    public const DOCUMENT = 'docs/integration/AUTHORIZATION_ROUTE_MATRIX.md';

    protected $signature = 'optinexus:route-matrix {--check : exit non-zero when the committed document is stale instead of writing it}';

    protected $description = 'Write (or verify) the authorization route matrix generated from the live route table.';

    public function handle(RouteAuthorizationMatrix $matrix): int
    {
        $path = base_path('../'.self::DOCUMENT);
        $content = $matrix->markdown()."\n";

        if ($this->option('check')) {
            if (! is_file($path) || file_get_contents($path) !== $content) {
                $this->error(self::DOCUMENT.' is stale: run `php artisan optinexus:route-matrix` and commit the result.');

                return self::FAILURE;
            }
            $this->info(self::DOCUMENT.' is current.');

            return self::SUCCESS;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $content);
        $this->info('Wrote '.self::DOCUMENT.' ('.$matrix->rows()->count().' routes).');

        return self::SUCCESS;
    }
}
