<?php

namespace App\Domain\AccessControl\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

/**
 * `code` is the stable, machine-readable identity of a system-defined role (e.g. PLATFORM_SUPERADMIN). It is
 * never translated or shown; `name` is the display text. Tenant-created roles have no code. Once set, a
 * code cannot change; it is unique within the platform or within one tenant.
 */
class Role extends Model
{
    use Auditable, HasUuids;

    public const PLATFORM_SUPERADMIN = 'PLATFORM_SUPERADMIN';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'scope',
        'is_system',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Role $role) {
            if ($role->code !== null && ! preg_match('/^[A-Z][A-Z0-9_]*$/', $role->code)) {
                throw new LogicException("Role code '{$role->code}' must be UPPER_SNAKE_CASE.");
            }
            if ($role->exists && $role->isDirty('code') && $role->getOriginal('code') !== null) {
                throw new LogicException('A system role code is immutable.');
            }
        });
    }

    /** The platform superadmin role, found by its code — never by its display name. */
    public static function platformSuperadmin(): ?self
    {
        return static::query()->whereNull('tenant_id')->where('code', self::PLATFORM_SUPERADMIN)->first();
    }

    /**
     * The platform superadmin role, created once. A role seeded before roles had codes (same scope, system,
     * seeded name, no code) receives the code instead of a duplicate being created; its name, permissions
     * and assignments are kept.
     */
    public static function ensurePlatformSuperadmin(): self
    {
        $role = static::platformSuperadmin()
            ?? static::query()->whereNull('tenant_id')->where('scope', 'platform')->where('is_system', true)
                ->whereNull('code')->where('name', 'Platform Superadmin')->first();
        if ($role) {
            if ($role->code === null) {
                $role->forceFill(['code' => self::PLATFORM_SUPERADMIN])->save();
            }

            return $role;
        }

        return static::query()->create([
            'tenant_id' => null, 'code' => self::PLATFORM_SUPERADMIN, 'name' => 'Platform Superadmin',
            'scope' => 'platform', 'is_system' => true, 'description' => 'Full platform access.',
        ]);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')->withTimestamps();
    }
}
