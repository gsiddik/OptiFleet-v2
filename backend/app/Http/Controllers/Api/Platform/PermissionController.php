<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\AccessControl\Models\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function index(Request $request)
    {
        $query = Permission::query();

        if ($scope = $request->string('scope')->value()) {
            $query->where('scope', $scope);
        }

        return $this->ok($query->orderBy('group')->orderBy('name')->get());
    }
}
