<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Models\Permission;
use App\Http\Controllers\Controller;

class PermissionController extends Controller
{
    public function index()
    {
        return $this->ok(
            Permission::query()->where('scope', 'tenant')->orderBy('group')->orderBy('name')->get()
        );
    }
}
