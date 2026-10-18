<?php

namespace App\Domain\Governance\Exceptions;

use RuntimeException;

/** OptiNexus answered allowed=false to product-context: the person lost this application, so the local session ends. */
class ProductSessionEnded extends RuntimeException
{
    public function __construct(public readonly ?string $reason)
    {
        parent::__construct('OptiNexus no longer allows this session.');
    }
}
