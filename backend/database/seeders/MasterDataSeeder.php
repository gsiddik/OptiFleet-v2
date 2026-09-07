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

        $groups = [
            'CG-ENGINE' => ['name' => 'Engine', 'parent' => null, 'children' => [
                'CG-ENGINE-LUBE' => 'Lubrication System',
                'CG-ENGINE-COOL' => 'Cooling System',
                'CG-ENGINE-FUEL' => 'Fuel System',
                'CG-ENGINE-EXHAUST' => 'Exhaust System',
            ]],
            'CG-CLUTCH' => ['name' => 'Clutch System / Torque Converter', 'parent' => null, 'children' => []],
            'CG-TRANS' => ['name' => 'Transmission System', 'parent' => null, 'children' => []],
            'CG-STEER' => ['name' => 'Steering System', 'parent' => null, 'children' => []],
            'CG-AXLE' => ['name' => 'Travel Drive / Axle Assy', 'parent' => null, 'children' => []],
            'CG-FRAME' => ['name' => 'Main Frame & Guard / Bogie', 'parent' => null, 'children' => []],
            'CG-ELEC' => ['name' => 'Electrical System', 'parent' => null, 'children' => []],
            'CG-BRAKE' => ['name' => 'Brake System', 'parent' => null, 'children' => []],
            'CG-SUSP' => ['name' => 'Suspension System', 'parent' => null, 'children' => []],
            'CG-HYD' => ['name' => 'Hydraulic System', 'parent' => null, 'children' => []],
            'CG-PNEU' => ['name' => 'Pneumatic System', 'parent' => null, 'children' => []],
            'CG-SWING' => ['name' => 'Swing System', 'parent' => null, 'children' => []],
            'CG-UC' => ['name' => 'Under Carriage', 'parent' => null, 'children' => []],
            'CG-TYRE' => ['name' => 'Tyre', 'parent' => null, 'children' => []],
            'CG-ATTACH' => ['name' => 'Attachment / Work Equipment', 'parent' => null, 'children' => []],
            'CG-ACC' => ['name' => 'Optional Accessories', 'parent' => null, 'children' => []],
        ];

        $sequence = 0;
        foreach ($groups as $code => $def) {
            $sequence += 10;
            $parent = ComponentGroup::query()->updateOrCreate(
                ['tenant_id' => null, 'code' => $code],
                ['name' => $def['name'], 'parent_id' => null, 'sequence' => $sequence, 'is_system' => true, 'status' => 'ACTIVE', 'tenant_id' => null]
            );

            $childSequence = 0;
            foreach ($def['children'] as $childCode => $childName) {
                $childSequence += 10;
                ComponentGroup::query()->updateOrCreate(
                    ['tenant_id' => null, 'code' => $childCode],
                    ['name' => $childName, 'parent_id' => $parent->id, 'sequence' => $childSequence, 'is_system' => true, 'status' => 'ACTIVE', 'tenant_id' => null]
                );
            }
        }

        $excavator = VehicleCategory::query()->where('code', 'VC-EXC')->whereNull('tenant_id')->first();
        $excavatorGroupCodes = ['CG-ENGINE', 'CG-AXLE', 'CG-ELEC', 'CG-UC', 'CG-ATTACH', 'CG-FRAME', 'CG-HYD', 'CG-SWING'];
        $excavatorGroupIds = ComponentGroup::query()->whereIn('code', $excavatorGroupCodes)->whereNull('tenant_id')->pluck('id');
        $excavator->componentGroups()->sync($excavatorGroupIds);

        $truck = VehicleCategory::query()->where('code', 'VC-TRUCK')->whereNull('tenant_id')->first();
        $truckGroupCodes = ['CG-ENGINE', 'CG-CLUTCH', 'CG-TRANS', 'CG-STEER', 'CG-AXLE', 'CG-ELEC', 'CG-BRAKE', 'CG-SUSP', 'CG-TYRE'];
        $truckGroupIds = ComponentGroup::query()->whereIn('code', $truckGroupCodes)->whereNull('tenant_id')->pluck('id');
        $truck->componentGroups()->sync($truckGroupIds);
    }
}
