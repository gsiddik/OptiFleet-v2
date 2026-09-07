<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePlatformUserRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PlatformUserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()->where('user_type', 'platform');

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        return $this->paginated($query->orderBy('name')->paginate($request->integer('per_page', 15)));
    }

    public function store(StorePlatformUserRequest $request)
    {
        $user = User::query()->create([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password')),
            'user_type' => 'platform',
            'status' => 'active',
        ]);

        return $this->ok($user, 201);
    }

    public function update(Request $request, User $user)
    {
        abort_unless($user->user_type === 'platform', 404);

        $status = $request->string('status')->value();
        if (in_array($status, ['active', 'inactive'], true)) {
            $user->update(['status' => $status]);
        }
        if ($name = $request->string('name')->value()) {
            $user->update(['name' => $name]);
        }

        return $this->ok($user);
    }
}
