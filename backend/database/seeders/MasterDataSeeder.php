<?php

namespace Database\Seeders;

use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\MasterData\Models\VehicleCategory;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['code' => 'VC-PCAR', 'name' => 'Passenger Car'],
            ['code' => 'VC-VAN', 'name' => 'Van'],
            ['code' => 'VC-BUS', 'name' => 'Bus'],
            ['code' => 'VC-TRUCK', 'name' => 'Truck'],
            ['code' => 'VC-EXC', 'name' => 'Excavator'],
            ['code' => 'VC-FORK', 'name' => 'Forklift'],
            ['code' => 'VC-HEQ', 'name' => 'Heavy Equipment'],
        ];

        foreach ($categories as $category) {
            VehicleCategory::query()->updateOrCreate(
                ['tenant_id' => null, 'code' => $category['code']],
                $category + ['tenant_id' => null, 'is_system' => true, 'status' => 'ACTIVE', 'description' => null]
            );
        }

        // Component Groups: production-safe baseline, see ComponentGroupSeeder.
        $this->call(ComponentGroupSeeder::class);

        // Default Vehicle Category <-> Component Group mappings are bootstrap
        // only: seeded when a category has no mapping yet, never re-synced
        // over a mapping an administrator has since curated.
        $this->seedDefaultMapping('VC-EXC', ['CG-ENGINE', 'CG-AXLE', 'CG-ELEC', 'CG-UC', 'CG-ATTACH', 'CG-FRAME', 'CG-HYD', 'CG-SWING']);
        $this->seedDefaultMapping('VC-TRUCK', ['CG-ENGINE', 'CG-CLUTCH', 'CG-TRANS', 'CG-STEER', 'CG-AXLE', 'CG-ELEC', 'CG-BRAKE', 'CG-SUSP', 'CG-TYRE']);
    }

    private function seedDefaultMapping(string $categoryCode, array $groupCodes): void
    {
        $category = VehicleCategory::query()->where('code', $categoryCode)->whereNull('tenant_id')->first();
        if ($category === null || $category->componentGroups()->exists()) {
            return;
        }

        $groupIds = ComponentGroup::query()->whereIn('code', $groupCodes)->whereNull('tenant_id')->pluck('id');
        $category->componentGroups()->sync($groupIds);
    }
}
