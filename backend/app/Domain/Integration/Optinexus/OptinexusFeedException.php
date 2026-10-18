<?php

namespace App\Domain\Integration\Optinexus;

use RuntimeException;

/** The gateway answered, but not with something this feed can use (shape, cursor going backwards). Nothing from that page is applied. */
class OptinexusFeedException extends RuntimeException
{
    public const CODE = 'GATEWAY_BAD_RESPONSE';
}
