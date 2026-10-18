<?php

namespace App\Domain\Governance\Exceptions;

use RuntimeException;

/** The product named by the browser is not one OptiNexus offers this person from here (direct targets are refused). */
class ProductNotAvailable extends RuntimeException
{
    public const CODE = 'PRODUCT_NOT_AVAILABLE';
}
