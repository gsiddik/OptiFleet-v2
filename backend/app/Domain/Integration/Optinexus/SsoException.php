<?php

namespace App\Domain\Integration\Optinexus;

use RuntimeException;

/** A sign-in failure whose message is a stable code the SPA can translate. */
class SsoException extends RuntimeException {}
