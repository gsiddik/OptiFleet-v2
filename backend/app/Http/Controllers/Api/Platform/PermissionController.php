<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\AccessControl\Services\PermissionCatalog;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    /**
     * Permissions with Module -> Feature -> Action metadata. `scope` narrows to one
     * scope (the platform Role editor always asks for scope=platform); without it both
     * scopes are returned, as before.
     */
    public function index(Request $request, PermissionCatalog $catalog)
    {
        $scope = $request->string('scope')->value();
        if (in_array($scope, ['platform', 'tenant'], true)) {
            return $this->ok($catalog->forScope($scope));
        }

        return $this->ok($catalog->forScope('platform')->concat($catalog->forScope('tenant'))->values());
    }
}
