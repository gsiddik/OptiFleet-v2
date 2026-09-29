<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\PermissionCatalog;
use App\Http\Controllers\Controller;

class PermissionController extends Controller
{
    /** Tenant permissions with Module -> Feature -> Action metadata for the Role editor. */
    public function index(PermissionCatalog $catalog)
    {
        return $this->ok($catalog->forScope('tenant'));
    }
}
