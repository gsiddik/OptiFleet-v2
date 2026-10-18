<?php

namespace App\Domain\Governance;

/**
 * The naming contract between OptiFleet's own catalog and OptiNexus (manifest v2, see FleetManifest):
 *  - permission key in OptiNexus = "<application code>.<local permission name>", e.g. "optifleet.vehicle.view";
 *  - the module (the commercial unit) of OptiFleet is a MODULE capability "<application code>.<module code in lower case>",
 *    e.g. "optifleet.work_order". A tenant holds a module in OptiFleet exactly when OptiNexus holds that capability entitlement
 *    for it (the four core modules come with the application itself).
 */
final class GovernanceCatalog
{
    public static function application(): string
    {
        return (string) config('optinexus.governance.application_code');
    }

    public static function permissionKey(string $localName): string
    {
        return self::application().'.'.$localName;
    }

    /** The local permission name for a key of this application, or null for any key that is not ours. */
    public static function localName(string $key): ?string
    {
        $prefix = self::application().'.';

        return str_starts_with($key, $prefix) && strlen($key) > strlen($prefix) ? substr($key, strlen($prefix)) : null;
    }

    public static function capabilityCode(string $moduleCode): string
    {
        return self::application().'.'.strtolower($moduleCode);
    }
}
