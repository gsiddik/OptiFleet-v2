<?php

namespace App\Console\Commands;

use App\Domain\Governance\FleetManifest;
use Illuminate\Console\Command;

class GovernanceManifestCommand extends Command
{
    protected $signature = 'optinexus:manifest {--output= : write the manifest JSON to this file instead of standard output} {--summary : print counts only}';

    protected $description = 'Export OptiFleet\'s permission and capability catalog as an OptiNexus manifest v2 (import it in OptiNexus: POST /applications/{id}/manifest).';

    public function handle(FleetManifest $manifest): int
    {
        $data = $manifest->build();

        if ($this->option('summary')) {
            $this->info(sprintf('manifest %s revision %s: %d capabilities, %d permissions (%d delegable)', $data['manifest_version'], $data['revision'],
                count($data['capabilities']), count($data['permissions']), count(array_filter(array_column($data['permissions'], 'delegable')))));

            return self::SUCCESS;
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($path = $this->option('output')) {
            file_put_contents($path, $json."\n");
            $this->info("Manifest written to {$path} (revision {$data['revision']}).");
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
