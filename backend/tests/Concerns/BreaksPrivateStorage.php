<?php

namespace Tests\Concerns;

use App\Domain\Shared\Services\DocumentStorageException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToCreateDirectory;

/**
 * Replaces the private `local` disk with one whose writes fail exactly like the production fault:
 * the runtime user may not create the tenant directory ("Unable to create a directory at …").
 */
trait BreaksPrivateStorage
{
    protected function breakPrivateStorage(): void
    {
        $adapter = new class(sys_get_temp_dir()) extends LocalFilesystemAdapter
        {
            public function write(string $path, string $contents, Config $config): void
            {
                $this->deny($path);
            }

            public function writeStream(string $path, $contents, Config $config): void
            {
                $this->deny($path);
            }

            private function deny(string $path): never
            {
                throw UnableToCreateDirectory::atLocation('/var/www/html/storage/app/private/'.dirname($path), 'mkdir(): Permission denied');
            }
        };

        Storage::set('local', new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => '/var/www/html/storage/app/private']));
    }

    /** The user-facing error is generic: no server path, no filesystem internals. */
    protected function assertStorageFailureResponse($response): void
    {
        $response->assertStatus(503)->assertJsonPath('message', DocumentStorageException::USER_MESSAGE);
        $this->assertStringNotContainsString('/var/www', $response->getContent());
        $this->assertStringNotContainsString('directory', strtolower($response->getContent()));
    }
}
