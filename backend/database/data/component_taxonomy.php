<?php

/*
 * Component Classification baseline taxonomy (L1 Component Group -> L2 Category /
 * Assembly -> L3 Subcategory / Component Family), transcribed 1:1 from the
 * authoritative reference docs/reference/component-taxonomy.md (every table row, in
 * source order). GENERATED ONCE and committed: codes are stored explicitly so they
 * never change with a slugging library. `line` is the source line of the row.
 *
 * item_types basis (see docs/status/COMPONENT_TAXONOMY_STATUS.md):
 *  - explicit          : the row's own Item Type column (Engine table)
 *  - explicit_section  : Wheel & Tyre source sub-heading (Tire / Rim / Wheel Related Spareparts; its Consumable category)
 *  - derived_material  : fluid/lubricant/chemical/consumable category, structurally equivalent to Engine 'Engine Service' (Consumable)
 *  - derived_filter    : replaceable filter element, treated by fleets as both Sparepart and Consumable
 *  - derived_component : hardware component, structurally equivalent to the Engine table's Sparepart rows
 *  - unmapped          : ambiguous (Attachment & Work Equipment, Optional Accessories, tyre Inner Components) -> unrestricted
 */

return [
    'CG-ENGINE' => [
        ['code' => 'CYLINDER_BLOCK', 'name' => 'Cylinder Block', 'subcategories' => [
            ['code' => 'CYLINDER_BLOCK', 'name' => 'Cylinder Block', 'description' => 'Examples: Engine block', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 18],
            ['code' => 'CYLINDER_LINER', 'name' => 'Cylinder Liner', 'description' => 'Examples: Wet liner, dry liner', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 19],
            ['code' => 'CORE_PLUG', 'name' => 'Core Plug', 'description' => 'Examples: Freeze plug, expansion plug', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 20],
            ['code' => 'MAIN_BEARING_CAP', 'name' => 'Main Bearing Cap', 'description' => 'Examples: Main cap', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 21],
            ['code' => 'ENGINE_BLOCK_PLUG', 'name' => 'Engine Block Plug', 'description' => 'Examples: Oil gallery plug', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 22],
        ]],
        ['code' => 'CYLINDER_HEAD', 'name' => 'Cylinder Head', 'subcategories' => [
            ['code' => 'CYLINDER_HEAD', 'name' => 'Cylinder Head', 'description' => 'Examples: Bare head, complete head', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 23],
            ['code' => 'HEAD_GASKET', 'name' => 'Head Gasket', 'description' => 'Examples: MLS gasket', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 24],
            ['code' => 'HEAD_BOLT', 'name' => 'Head Bolt', 'description' => 'Examples: Cylinder head bolt', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 25],
            ['code' => 'VALVE_COVER', 'name' => 'Valve Cover', 'description' => 'Examples: Rocker cover', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 26],
            ['code' => 'VALVE_COVER_GASKET', 'name' => 'Valve Cover Gasket', 'description' => 'Examples: Cover gasket', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 27],
        ]],
        ['code' => 'PISTON_ASSEMBLY', 'name' => 'Piston Assembly', 'subcategories' => [
            ['code' => 'PISTON', 'name' => 'Piston', 'description' => 'Examples: Standard/oversize piston', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 28],
            ['code' => 'PISTON_RING', 'name' => 'Piston Ring', 'description' => 'Examples: Compression/oil ring', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 29],
            ['code' => 'PISTON_PIN', 'name' => 'Piston Pin', 'description' => 'Examples: Wrist pin', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 30],
            ['code' => 'PISTON_PIN_BUSH', 'name' => 'Piston Pin Bush', 'description' => 'Examples: Small-end bush', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 31],
            ['code' => 'CIRCLIP', 'name' => 'Circlip', 'description' => 'Examples: Piston pin circlip', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 32],
        ]],
        ['code' => 'CONNECTING_ROD', 'name' => 'Connecting Rod', 'subcategories' => [
            ['code' => 'CONNECTING_ROD', 'name' => 'Connecting Rod', 'description' => 'Examples: Conrod', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 33],
            ['code' => 'BIG_END_BEARING', 'name' => 'Big-End Bearing', 'description' => 'Examples: Conrod bearing', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 34],
            ['code' => 'CONNECTING_ROD_BOLT', 'name' => 'Connecting Rod Bolt', 'description' => 'Examples: Rod bolt/nut', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 35],
        ]],
        ['code' => 'CRANKSHAFT', 'name' => 'Crankshaft', 'subcategories' => [
            ['code' => 'CRANKSHAFT', 'name' => 'Crankshaft', 'description' => 'Examples: Crankshaft assembly', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 36],
            ['code' => 'MAIN_BEARING', 'name' => 'Main Bearing', 'description' => 'Examples: Main journal bearing', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 37],
            ['code' => 'THRUST_BEARING', 'name' => 'Thrust Bearing', 'description' => 'Examples: Thrust washer', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 38],
            ['code' => 'CRANKSHAFT_GEAR', 'name' => 'Crankshaft Gear', 'description' => 'Examples: Timing gear', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 39],
            ['code' => 'FRONT_OIL_SEAL', 'name' => 'Front Oil Seal', 'description' => 'Examples: Crank seal', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 40],
            ['code' => 'REAR_MAIN_SEAL', 'name' => 'Rear Main Seal', 'description' => 'Examples: Rear crank seal', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 41],
        ]],
        ['code' => 'CAMSHAFT', 'name' => 'Camshaft', 'subcategories' => [
            ['code' => 'CAMSHAFT', 'name' => 'Camshaft', 'description' => 'Examples: Intake/exhaust camshaft', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 42],
            ['code' => 'CAM_BEARING', 'name' => 'Cam Bearing', 'description' => 'Examples: Cam bush/bearing', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 43],
            ['code' => 'CAM_GEAR', 'name' => 'Cam Gear', 'description' => 'Examples: Camshaft sprocket', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 44],
        ]],
        ['code' => 'VALVE_TRAIN', 'name' => 'Valve Train', 'subcategories' => [
            ['code' => 'INTAKE_VALVE', 'name' => 'Intake Valve', 'description' => 'Examples: Intake valve', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 45],
            ['code' => 'EXHAUST_VALVE', 'name' => 'Exhaust Valve', 'description' => 'Examples: Exhaust valve', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 46],
            ['code' => 'VALVE_GUIDE', 'name' => 'Valve Guide', 'description' => 'Examples: Guide', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 47],
            ['code' => 'VALVE_SEAT', 'name' => 'Valve Seat', 'description' => 'Examples: Seat insert', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 48],
            ['code' => 'VALVE_SPRING', 'name' => 'Valve Spring', 'description' => 'Examples: Inner/outer spring', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 49],
            ['code' => 'VALVE_KEEPER', 'name' => 'Valve Keeper', 'description' => 'Examples: Collet/lock', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 50],
            ['code' => 'VALVE_STEM_SEAL', 'name' => 'Valve Stem Seal', 'description' => 'Examples: Stem seal', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 51],
            ['code' => 'ROCKER_ARM', 'name' => 'Rocker Arm', 'description' => 'Examples: Rocker arm', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 52],
            ['code' => 'ROCKER_SHAFT', 'name' => 'Rocker Shaft', 'description' => 'Examples: Rocker shaft', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 53],
            ['code' => 'PUSH_ROD', 'name' => 'Push Rod', 'description' => 'Examples: Push rod', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 54],
            ['code' => 'TAPPET_LIFTER', 'name' => 'Tappet / Lifter', 'description' => 'Examples: Hydraulic/mechanical lifter', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 55],
            ['code' => 'LASH_ADJUSTER', 'name' => 'Lash Adjuster', 'description' => 'Examples: Hydraulic lash adjuster', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 56],
        ]],
        ['code' => 'TIMING_SYSTEM', 'name' => 'Timing System', 'subcategories' => [
            ['code' => 'TIMING_BELT', 'name' => 'Timing Belt', 'description' => 'Examples: Timing belt', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 57],
            ['code' => 'TIMING_CHAIN', 'name' => 'Timing Chain', 'description' => 'Examples: Timing chain', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 58],
            ['code' => 'TIMING_GEAR', 'name' => 'Timing Gear', 'description' => 'Examples: Gear train', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 59],
            ['code' => 'TENSIONER', 'name' => 'Tensioner', 'description' => 'Examples: Hydraulic/manual tensioner', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 60],
            ['code' => 'GUIDE', 'name' => 'Guide', 'description' => 'Examples: Chain guide', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 61],
            ['code' => 'IDLER_PULLEY', 'name' => 'Idler Pulley', 'description' => 'Examples: Timing idler', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 62],
            ['code' => 'TIMING_COVER', 'name' => 'Timing Cover', 'description' => 'Examples: Front cover', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 63],
        ]],
        ['code' => 'BALANCE_SYSTEM', 'name' => 'Balance System', 'subcategories' => [
            ['code' => 'BALANCE_SHAFT', 'name' => 'Balance Shaft', 'description' => 'Examples: Balance shaft', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 64],
            ['code' => 'BALANCE_SHAFT_BEARING', 'name' => 'Balance Shaft Bearing', 'description' => 'Examples: Bearing/bush', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 65],
        ]],
        ['code' => 'FLYWHEEL', 'name' => 'Flywheel', 'subcategories' => [
            ['code' => 'FLYWHEEL', 'name' => 'Flywheel', 'description' => 'Examples: Flywheel', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 66],
            ['code' => 'RING_GEAR', 'name' => 'Ring Gear', 'description' => 'Examples: Starter ring gear', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 67],
        ]],
        ['code' => 'FLEXPLATE', 'name' => 'Flexplate', 'subcategories' => [
            ['code' => 'FLEXPLATE', 'name' => 'Flexplate', 'description' => 'Examples: Automatic transmission flexplate', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 68],
        ]],
        ['code' => 'ENGINE_MOUNTING', 'name' => 'Engine Mounting', 'subcategories' => [
            ['code' => 'ENGINE_MOUNT', 'name' => 'Engine Mount', 'description' => 'Examples: Rubber/hydraulic mount', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 69],
            ['code' => 'MOUNTING_BRACKET', 'name' => 'Mounting Bracket', 'description' => 'Examples: LH/RH bracket', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 70],
        ]],
        ['code' => 'AIR_INTAKE', 'name' => 'Air Intake', 'subcategories' => [
            ['code' => 'INTAKE_MANIFOLD', 'name' => 'Intake Manifold', 'description' => 'Examples: Intake manifold', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 71],
            ['code' => 'AIR_INTAKE_HOSE', 'name' => 'Air Intake Hose', 'description' => 'Examples: Intake hose', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 72],
            ['code' => 'AIR_RESONATOR', 'name' => 'Air Resonator', 'description' => 'Examples: Resonator', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 73],
            ['code' => 'THROTTLE_BODY', 'name' => 'Throttle Body', 'description' => 'Examples: Electronic/mechanical throttle', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 74],
            ['code' => 'INTAKE_GASKET', 'name' => 'Intake Gasket', 'description' => 'Examples: Manifold gasket', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 75],
        ]],
        ['code' => 'TURBOCHARGING', 'name' => 'Turbocharging', 'subcategories' => [
            ['code' => 'TURBOCHARGER', 'name' => 'Turbocharger', 'description' => 'Examples: Turbo assembly', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 76],
            ['code' => 'TURBO_CARTRIDGE', 'name' => 'Turbo Cartridge', 'description' => 'Examples: CHRA', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 77],
            ['code' => 'WASTEGATE', 'name' => 'Wastegate', 'description' => 'Examples: Actuator/wastegate', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 78],
            ['code' => 'VGT_ACTUATOR', 'name' => 'VGT Actuator', 'description' => 'Examples: Electronic/pneumatic actuator', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 79],
            ['code' => 'TURBO_OIL_LINE', 'name' => 'Turbo Oil Line', 'description' => 'Examples: Feed/return pipe', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 80],
            ['code' => 'TURBO_COOLANT_LINE', 'name' => 'Turbo Coolant Line', 'description' => 'Examples: Coolant hose/pipe', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 81],
        ]],
        ['code' => 'CHARGE_AIR', 'name' => 'Charge Air', 'subcategories' => [
            ['code' => 'INTERCOOLER', 'name' => 'Intercooler', 'description' => 'Examples: Charge air cooler', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 82],
            ['code' => 'INTERCOOLER_HOSE', 'name' => 'Intercooler Hose', 'description' => 'Examples: Boost hose', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 83],
            ['code' => 'CHARGE_PIPE', 'name' => 'Charge Pipe', 'description' => 'Examples: Aluminum/plastic pipe', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 84],
        ]],
        ['code' => 'CRANKCASE_VENTILATION', 'name' => 'Crankcase Ventilation', 'subcategories' => [
            ['code' => 'PCV_VALVE', 'name' => 'PCV Valve', 'description' => 'Examples: PCV valve', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 85],
            ['code' => 'BREATHER_HOSE', 'name' => 'Breather Hose', 'description' => 'Examples: Hose', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 86],
        ]],
        ['code' => 'ENGINE_SENSORS', 'name' => 'Engine Sensors', 'subcategories' => [
            ['code' => 'CRANKSHAFT_SENSOR', 'name' => 'Crankshaft Sensor', 'description' => 'Examples: CKP sensor', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 87],
            ['code' => 'CAMSHAFT_SENSOR', 'name' => 'Camshaft Sensor', 'description' => 'Examples: CMP sensor', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 88],
            ['code' => 'KNOCK_SENSOR', 'name' => 'Knock Sensor', 'description' => 'Examples: Knock sensor', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 89],
            ['code' => 'MANIFOLD_PRESSURE_SENSOR', 'name' => 'Manifold Pressure Sensor', 'description' => 'Examples: MAP sensor', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 90],
            ['code' => 'AIR_FLOW_SENSOR', 'name' => 'Air Flow Sensor', 'description' => 'Examples: MAF sensor', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 91],
            ['code' => 'INTAKE_AIR_TEMP_SENSOR', 'name' => 'Intake Air Temp Sensor', 'description' => 'Examples: IAT sensor', 'item_types' => ['SPARE_PART'], 'basis' => 'explicit', 'line' => 92],
        ]],
        ['code' => 'ENGINE_SERVICE', 'name' => 'Engine Service', 'subcategories' => [
            ['code' => 'ENGINE_CLEANER', 'name' => 'Engine Cleaner', 'description' => 'Examples: Carbon cleaner', 'item_types' => ['CONSUMABLE'], 'basis' => 'explicit', 'line' => 93],
            ['code' => 'GASKET_MAKER', 'name' => 'Gasket Maker', 'description' => 'Examples: RTV sealant', 'item_types' => ['CONSUMABLE'], 'basis' => 'explicit', 'line' => 94],
        ]],
    ],
    'CG-ENGINE-LUBE' => [
        ['code' => 'ENGINE_LUBRICANT', 'name' => 'Engine Lubricant', 'subcategories' => [
            ['code' => 'MINERAL_ENGINE_OIL', 'name' => 'Mineral Engine Oil', 'description' => 'Examples: SAE 15W-40', 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 102],
            ['code' => 'SEMI_SYNTHETIC_ENGINE_OIL', 'name' => 'Semi-Synthetic Engine Oil', 'description' => 'Examples: SAE 10W-40', 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 103],
            ['code' => 'FULLY_SYNTHETIC_ENGINE_OIL', 'name' => 'Fully Synthetic Engine Oil', 'description' => 'Examples: SAE 5W-30', 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 104],
        ]],
        ['code' => 'GEAR_LUBRICANT', 'name' => 'Gear Lubricant', 'subcategories' => [
            ['code' => 'MANUAL_TRANSMISSION_OIL', 'name' => 'Manual Transmission Oil', 'description' => 'Examples: 75W-90', 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 105],
            ['code' => 'DIFFERENTIAL_OIL', 'name' => 'Differential Oil', 'description' => 'Examples: 80W-90', 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 106],
        ]],
        ['code' => 'AUTOMATIC_TRANSMISSION', 'name' => 'Automatic Transmission', 'subcategories' => [
            ['code' => 'ATF', 'name' => 'ATF', 'description' => 'Examples: Dexron, ATF WS', 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 107],
        ]],
        ['code' => 'CVT', 'name' => 'CVT', 'subcategories' => [
            ['code' => 'CVT_FLUID', 'name' => 'CVT Fluid', 'description' => 'Examples: CVTF', 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 108],
        ]],
        ['code' => 'HYDRAULIC_LUBRICANT', 'name' => 'Hydraulic Lubricant', 'subcategories' => [
            ['code' => 'HYDRAULIC_OIL', 'name' => 'Hydraulic Oil', 'description' => 'Examples: ISO VG 32/46/68', 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 109],
        ]],
        ['code' => 'GREASE', 'name' => 'Grease', 'subcategories' => [
            ['code' => 'MULTIPURPOSE_GREASE', 'name' => 'Multipurpose Grease', 'description' => 'Examples: Lithium grease', 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 110],
            ['code' => 'HIGH_TEMPERATURE_GREASE', 'name' => 'High Temperature Grease', 'description' => 'Examples: Bearing grease', 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 111],
            ['code' => 'EP_GREASE', 'name' => 'EP Grease', 'description' => 'Examples: Extreme-pressure grease', 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 112],
        ]],
        ['code' => 'OIL_PUMP', 'name' => 'Oil Pump', 'subcategories' => [
            ['code' => 'ENGINE_OIL_PUMP', 'name' => 'Engine Oil Pump', 'description' => 'Examples: Rotor/gear pump', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 113],
            ['code' => 'PUMP_HOUSING', 'name' => 'Pump Housing', 'description' => 'Examples: Oil pump housing', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 114],
            ['code' => 'PRESSURE_RELIEF_VALVE', 'name' => 'Pressure Relief Valve', 'description' => 'Examples: Relief valve', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 115],
        ]],
        ['code' => 'OIL_SUMP', 'name' => 'Oil Sump', 'subcategories' => [
            ['code' => 'OIL_PAN', 'name' => 'Oil Pan', 'description' => 'Examples: Sump', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 116],
            ['code' => 'DRAIN_PLUG', 'name' => 'Drain Plug', 'description' => 'Examples: Drain bolt', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 117],
            ['code' => 'DRAIN_PLUG_WASHER', 'name' => 'Drain Plug Washer', 'description' => 'Examples: Copper/aluminum washer', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 118],
        ]],
        ['code' => 'OIL_PICKUP', 'name' => 'Oil Pickup', 'subcategories' => [
            ['code' => 'PICKUP_TUBE', 'name' => 'Pickup Tube', 'description' => 'Examples: Suction tube', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 119],
            ['code' => 'STRAINER', 'name' => 'Strainer', 'description' => 'Examples: Pickup strainer', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 120],
        ]],
        ['code' => 'OIL_FILTER', 'name' => 'Oil Filter', 'subcategories' => [
            ['code' => 'SPIN_ON_FILTER', 'name' => 'Spin-On Filter', 'description' => 'Examples: Engine filter', 'item_types' => ['SPARE_PART', 'CONSUMABLE'], 'basis' => 'derived_filter', 'line' => 121],
            ['code' => 'CARTRIDGE_FILTER', 'name' => 'Cartridge Filter', 'description' => 'Examples: Cartridge element', 'item_types' => ['SPARE_PART', 'CONSUMABLE'], 'basis' => 'derived_filter', 'line' => 122],
            ['code' => 'FILTER_HOUSING', 'name' => 'Filter Housing', 'description' => 'Examples: Oil filter housing', 'item_types' => ['SPARE_PART', 'CONSUMABLE'], 'basis' => 'derived_filter', 'line' => 123],
        ]],
        ['code' => 'OIL_COOLER', 'name' => 'Oil Cooler', 'subcategories' => [
            ['code' => 'OIL_COOLER', 'name' => 'Oil Cooler', 'description' => 'Examples: Plate-type cooler', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 124],
            ['code' => 'COOLER_GASKET', 'name' => 'Cooler Gasket', 'description' => 'Examples: Seal/gasket', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 125],
            ['code' => 'COOLER_HOSE', 'name' => 'Cooler Hose', 'description' => 'Examples: Oil hose', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 126],
        ]],
        ['code' => 'OIL_LINE', 'name' => 'Oil Line', 'subcategories' => [
            ['code' => 'OIL_PIPE', 'name' => 'Oil Pipe', 'description' => 'Examples: Feed pipe', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 127],
            ['code' => 'FLEXIBLE_OIL_HOSE', 'name' => 'Flexible Oil Hose', 'description' => 'Examples: Hose', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 128],
        ]],
        ['code' => 'PRESSURE_MONITORING', 'name' => 'Pressure Monitoring', 'subcategories' => [
            ['code' => 'OIL_PRESSURE_SENSOR', 'name' => 'Oil Pressure Sensor', 'description' => 'Examples: Pressure sensor', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 129],
            ['code' => 'OIL_PRESSURE_SWITCH', 'name' => 'Oil Pressure Switch', 'description' => 'Examples: Warning switch', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 130],
        ]],
        ['code' => 'LEVEL_MONITORING', 'name' => 'Level Monitoring', 'subcategories' => [
            ['code' => 'OIL_LEVEL_SENSOR', 'name' => 'Oil Level Sensor', 'description' => 'Examples: Level sensor', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 131],
        ]],
        ['code' => 'DIPSTICK', 'name' => 'Dipstick', 'subcategories' => [
            ['code' => 'OIL_DIPSTICK', 'name' => 'Oil Dipstick', 'description' => 'Examples: Dipstick', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 132],
            ['code' => 'DIPSTICK_TUBE', 'name' => 'Dipstick Tube', 'description' => 'Examples: Tube', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 133],
        ]],
        ['code' => 'LUBRICATION_SEALING', 'name' => 'Lubrication Sealing', 'subcategories' => [
            ['code' => 'O_RING', 'name' => 'O-Ring', 'description' => 'Examples: Various', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 134],
            ['code' => 'OIL_SEAL', 'name' => 'Oil Seal', 'description' => 'Examples: Various', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 135],
            ['code' => 'GASKET', 'name' => 'Gasket', 'description' => 'Examples: Various', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 136],
        ]],
    ],
    'CG-CLUTCH' => [
        ['code' => 'CLUTCH_DISC_ASSEMBLY', 'name' => 'Clutch Disc Assembly', 'subcategories' => [
            ['code' => 'CLUTCH_DISC', 'name' => 'Clutch Disc', 'description' => 'Examples: Friction disc', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 144],
            ['code' => 'CLUTCH_LINING', 'name' => 'Clutch Lining', 'description' => 'Examples: Friction lining', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 145],
        ]],
        ['code' => 'PRESSURE_PLATE', 'name' => 'Pressure Plate', 'subcategories' => [
            ['code' => 'PRESSURE_PLATE', 'name' => 'Pressure Plate', 'description' => 'Examples: Clutch cover', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 146],
            ['code' => 'DIAPHRAGM_SPRING', 'name' => 'Diaphragm Spring', 'description' => 'Examples: Spring', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 147],
        ]],
        ['code' => 'RELEASE_MECHANISM', 'name' => 'Release Mechanism', 'subcategories' => [
            ['code' => 'RELEASE_BEARING', 'name' => 'Release Bearing', 'description' => 'Examples: Throw-out bearing', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 148],
            ['code' => 'RELEASE_FORK', 'name' => 'Release Fork', 'description' => 'Examples: Clutch fork', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 149],
            ['code' => 'PIVOT_BALL', 'name' => 'Pivot Ball', 'description' => 'Examples: Pivot', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 150],
            ['code' => 'RELEASE_SLEEVE', 'name' => 'Release Sleeve', 'description' => 'Examples: Guide sleeve', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 151],
        ]],
        ['code' => 'PILOT_SYSTEM', 'name' => 'Pilot System', 'subcategories' => [
            ['code' => 'PILOT_BEARING', 'name' => 'Pilot Bearing', 'description' => 'Examples: Pilot bearing', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 152],
            ['code' => 'PILOT_BUSH', 'name' => 'Pilot Bush', 'description' => 'Examples: Pilot bush', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 153],
        ]],
        ['code' => 'CLUTCH_HYDRAULICS', 'name' => 'Clutch Hydraulics', 'subcategories' => [
            ['code' => 'MASTER_CYLINDER', 'name' => 'Master Cylinder', 'description' => 'Examples: Clutch master', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 154],
            ['code' => 'SLAVE_CYLINDER', 'name' => 'Slave Cylinder', 'description' => 'Examples: Clutch slave', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 155],
            ['code' => 'HYDRAULIC_HOSE', 'name' => 'Hydraulic Hose', 'description' => 'Examples: Flexible hose', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 156],
            ['code' => 'HYDRAULIC_PIPE', 'name' => 'Hydraulic Pipe', 'description' => 'Examples: Hard line', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 157],
            ['code' => 'REPAIR_KIT', 'name' => 'Repair Kit', 'description' => 'Examples: Cup/seal kit', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 158],
        ]],
        ['code' => 'CLUTCH_MECHANICAL', 'name' => 'Clutch Mechanical', 'subcategories' => [
            ['code' => 'CLUTCH_CABLE', 'name' => 'Clutch Cable', 'description' => 'Examples: Cable', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 159],
            ['code' => 'PEDAL_LINKAGE', 'name' => 'Pedal Linkage', 'description' => 'Examples: Link/rod', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 160],
        ]],
        ['code' => 'CLUTCH_PEDAL', 'name' => 'Clutch Pedal', 'subcategories' => [
            ['code' => 'PEDAL_ASSEMBLY', 'name' => 'Pedal Assembly', 'description' => 'Examples: Pedal', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 161],
            ['code' => 'PEDAL_BUSH', 'name' => 'Pedal Bush', 'description' => 'Examples: Bushing', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 162],
        ]],
        ['code' => 'FLYWHEEL', 'name' => 'Flywheel', 'subcategories' => [
            ['code' => 'FLYWHEEL', 'name' => 'Flywheel', 'description' => 'Examples: Single mass', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 163],
            ['code' => 'DUAL_MASS_FLYWHEEL', 'name' => 'Dual Mass Flywheel', 'description' => 'Examples: DMF', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 164],
        ]],
        ['code' => 'TORQUE_CONVERTER', 'name' => 'Torque Converter', 'subcategories' => [
            ['code' => 'TORQUE_CONVERTER_ASSEMBLY', 'name' => 'Torque Converter Assembly', 'description' => 'Examples: Converter', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 165],
            ['code' => 'LOCK_UP_CLUTCH', 'name' => 'Lock-Up Clutch', 'description' => 'Examples: Lock-up assembly', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 166],
            ['code' => 'STATOR', 'name' => 'Stator', 'description' => 'Examples: Stator', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 167],
            ['code' => 'TURBINE', 'name' => 'Turbine', 'description' => 'Examples: Turbine', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 168],
            ['code' => 'IMPELLER', 'name' => 'Impeller', 'description' => 'Examples: Pump/impeller', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 169],
        ]],
        ['code' => 'TORQUE_CONVERTER_CONTROL', 'name' => 'Torque Converter Control', 'subcategories' => [
            ['code' => 'LOCK_UP_SOLENOID', 'name' => 'Lock-Up Solenoid', 'description' => 'Examples: Solenoid', 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 170],
        ]],
        ['code' => 'CLUTCH_FLUID', 'name' => 'Clutch Fluid', 'subcategories' => [
            ['code' => 'BRAKE_CLUTCH_FLUID', 'name' => 'Brake/Clutch Fluid', 'description' => 'Examples: DOT 3/4', 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 171],
        ]],
    ],
    'CG-ENGINE-COOL' => [
        ['code' => 'RADIATOR', 'name' => 'Radiator', 'subcategories' => [
            ['code' => 'RADIATOR_ASSEMBLY', 'name' => 'Radiator Assembly', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 179],
            ['code' => 'RADIATOR_CORE', 'name' => 'Radiator Core', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 180],
            ['code' => 'UPPER_TANK', 'name' => 'Upper Tank', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 181],
            ['code' => 'LOWER_TANK', 'name' => 'Lower Tank', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 182],
            ['code' => 'RADIATOR_CAP', 'name' => 'Radiator Cap', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 183],
        ]],
        ['code' => 'WATER_PUMP', 'name' => 'Water Pump', 'subcategories' => [
            ['code' => 'MECHANICAL_WATER_PUMP', 'name' => 'Mechanical Water Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 184],
            ['code' => 'ELECTRIC_WATER_PUMP', 'name' => 'Electric Water Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 185],
            ['code' => 'PUMP_IMPELLER', 'name' => 'Pump Impeller', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 186],
            ['code' => 'PUMP_GASKET', 'name' => 'Pump Gasket', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 187],
        ]],
        ['code' => 'THERMOSTAT', 'name' => 'Thermostat', 'subcategories' => [
            ['code' => 'THERMOSTAT', 'name' => 'Thermostat', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 188],
            ['code' => 'THERMOSTAT_HOUSING', 'name' => 'Thermostat Housing', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 189],
        ]],
        ['code' => 'FAN', 'name' => 'Fan', 'subcategories' => [
            ['code' => 'MECHANICAL_FAN', 'name' => 'Mechanical Fan', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 190],
            ['code' => 'ELECTRIC_FAN', 'name' => 'Electric Fan', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 191],
            ['code' => 'FAN_BLADE', 'name' => 'Fan Blade', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 192],
            ['code' => 'FAN_MOTOR', 'name' => 'Fan Motor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 193],
            ['code' => 'VISCOUS_FAN_CLUTCH', 'name' => 'Viscous Fan Clutch', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 194],
        ]],
        ['code' => 'FAN_CONTROL', 'name' => 'Fan Control', 'subcategories' => [
            ['code' => 'FAN_RELAY', 'name' => 'Fan Relay', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 195],
            ['code' => 'FAN_CONTROLLER', 'name' => 'Fan Controller', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 196],
        ]],
        ['code' => 'HOSE', 'name' => 'Hose', 'subcategories' => [
            ['code' => 'UPPER_RADIATOR_HOSE', 'name' => 'Upper Radiator Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 197],
            ['code' => 'LOWER_RADIATOR_HOSE', 'name' => 'Lower Radiator Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 198],
            ['code' => 'BYPASS_HOSE', 'name' => 'Bypass Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 199],
            ['code' => 'HEATER_HOSE', 'name' => 'Heater Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 200],
            ['code' => 'TURBO_COOLANT_HOSE', 'name' => 'Turbo Coolant Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 201],
        ]],
        ['code' => 'PIPE', 'name' => 'Pipe', 'subcategories' => [
            ['code' => 'COOLANT_PIPE', 'name' => 'Coolant Pipe', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 202],
            ['code' => 'WATER_OUTLET', 'name' => 'Water Outlet', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 203],
        ]],
        ['code' => 'EXPANSION', 'name' => 'Expansion', 'subcategories' => [
            ['code' => 'EXPANSION_TANK', 'name' => 'Expansion Tank', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 204],
            ['code' => 'RESERVOIR_CAP', 'name' => 'Reservoir Cap', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 205],
        ]],
        ['code' => 'SENSOR', 'name' => 'Sensor', 'subcategories' => [
            ['code' => 'COOLANT_TEMPERATURE_SENSOR', 'name' => 'Coolant Temperature Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 206],
            ['code' => 'COOLANT_LEVEL_SENSOR', 'name' => 'Coolant Level Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 207],
            ['code' => 'FAN_SWITCH', 'name' => 'Fan Switch', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 208],
        ]],
        ['code' => 'HEATER_CIRCUIT', 'name' => 'Heater Circuit', 'subcategories' => [
            ['code' => 'HEATER_CORE', 'name' => 'Heater Core', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 209],
            ['code' => 'HEATER_VALVE', 'name' => 'Heater Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 210],
        ]],
        ['code' => 'COOLER', 'name' => 'Cooler', 'subcategories' => [
            ['code' => 'TRANSMISSION_OIL_COOLER', 'name' => 'Transmission Oil Cooler', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 211],
            ['code' => 'ENGINE_OIL_COOLER', 'name' => 'Engine Oil Cooler', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 212],
        ]],
        ['code' => 'CLAMP', 'name' => 'Clamp', 'subcategories' => [
            ['code' => 'HOSE_CLAMP', 'name' => 'Hose Clamp', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 213],
        ]],
        ['code' => 'COOLANT', 'name' => 'Coolant', 'subcategories' => [
            ['code' => 'READY_MIX_COOLANT', 'name' => 'Ready-Mix Coolant', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 214],
            ['code' => 'COOLANT_CONCENTRATE', 'name' => 'Coolant Concentrate', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 215],
        ]],
        ['code' => 'CHEMICAL', 'name' => 'Chemical', 'subcategories' => [
            ['code' => 'RADIATOR_FLUSH', 'name' => 'Radiator Flush', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 216],
            ['code' => 'COOLING_SYSTEM_SEALER', 'name' => 'Cooling System Sealer', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 217],
        ]],
    ],
    'CG-ENGINE-FUEL' => [
        ['code' => 'FUEL_STORAGE', 'name' => 'Fuel Storage', 'subcategories' => [
            ['code' => 'FUEL_TANK', 'name' => 'Fuel Tank', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 225],
            ['code' => 'FUEL_TANK_CAP', 'name' => 'Fuel Tank Cap', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 226],
            ['code' => 'TANK_STRAP', 'name' => 'Tank Strap', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 227],
            ['code' => 'TANK_SENDER', 'name' => 'Tank Sender', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 228],
        ]],
        ['code' => 'FUEL_DELIVERY', 'name' => 'Fuel Delivery', 'subcategories' => [
            ['code' => 'ELECTRIC_FUEL_PUMP', 'name' => 'Electric Fuel Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 229],
            ['code' => 'MECHANICAL_FUEL_PUMP', 'name' => 'Mechanical Fuel Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 230],
            ['code' => 'LIFT_PUMP', 'name' => 'Lift Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 231],
            ['code' => 'HIGH_PRESSURE_PUMP', 'name' => 'High Pressure Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 232],
            ['code' => 'INJECTION_PUMP', 'name' => 'Injection Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 233],
        ]],
        ['code' => 'FUEL_FILTER', 'name' => 'Fuel Filter', 'subcategories' => [
            ['code' => 'PRIMARY_FUEL_FILTER', 'name' => 'Primary Fuel Filter', 'description' => null, 'item_types' => ['SPARE_PART', 'CONSUMABLE'], 'basis' => 'derived_filter', 'line' => 234],
            ['code' => 'SECONDARY_FUEL_FILTER', 'name' => 'Secondary Fuel Filter', 'description' => null, 'item_types' => ['SPARE_PART', 'CONSUMABLE'], 'basis' => 'derived_filter', 'line' => 235],
            ['code' => 'INLINE_FUEL_FILTER', 'name' => 'Inline Fuel Filter', 'description' => null, 'item_types' => ['SPARE_PART', 'CONSUMABLE'], 'basis' => 'derived_filter', 'line' => 236],
        ]],
        ['code' => 'WATER_SEPARATOR', 'name' => 'Water Separator', 'subcategories' => [
            ['code' => 'WATER_SEPARATOR', 'name' => 'Water Separator', 'description' => null, 'item_types' => ['SPARE_PART', 'CONSUMABLE'], 'basis' => 'derived_filter', 'line' => 237],
            ['code' => 'SEPARATOR_ELEMENT', 'name' => 'Separator Element', 'description' => null, 'item_types' => ['SPARE_PART', 'CONSUMABLE'], 'basis' => 'derived_filter', 'line' => 238],
        ]],
        ['code' => 'INJECTION', 'name' => 'Injection', 'subcategories' => [
            ['code' => 'FUEL_INJECTOR', 'name' => 'Fuel Injector', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 239],
            ['code' => 'INJECTOR_NOZZLE', 'name' => 'Injector Nozzle', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 240],
            ['code' => 'INJECTOR_SEAL', 'name' => 'Injector Seal', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 241],
            ['code' => 'INJECTOR_WASHER', 'name' => 'Injector Washer', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 242],
            ['code' => 'INJECTOR_RETURN_LINE', 'name' => 'Injector Return Line', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 243],
        ]],
        ['code' => 'COMMON_RAIL', 'name' => 'Common Rail', 'subcategories' => [
            ['code' => 'FUEL_RAIL', 'name' => 'Fuel Rail', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 244],
            ['code' => 'RAIL_PRESSURE_SENSOR', 'name' => 'Rail Pressure Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 245],
            ['code' => 'PRESSURE_CONTROL_VALVE', 'name' => 'Pressure Control Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 246],
            ['code' => 'PRESSURE_LIMITING_VALVE', 'name' => 'Pressure Limiting Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 247],
        ]],
        ['code' => 'FUEL_LINE', 'name' => 'Fuel Line', 'subcategories' => [
            ['code' => 'SUPPLY_HOSE', 'name' => 'Supply Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 248],
            ['code' => 'RETURN_HOSE', 'name' => 'Return Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 249],
            ['code' => 'STEEL_FUEL_PIPE', 'name' => 'Steel Fuel Pipe', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 250],
            ['code' => 'QUICK_CONNECTOR', 'name' => 'Quick Connector', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 251],
        ]],
        ['code' => 'CARBURETOR', 'name' => 'Carburetor', 'subcategories' => [
            ['code' => 'CARBURETOR_ASSEMBLY', 'name' => 'Carburetor Assembly', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 252],
            ['code' => 'FLOAT', 'name' => 'Float', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 253],
            ['code' => 'JET', 'name' => 'Jet', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 254],
            ['code' => 'NEEDLE_VALVE', 'name' => 'Needle Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 255],
        ]],
        ['code' => 'THROTTLE', 'name' => 'Throttle', 'subcategories' => [
            ['code' => 'THROTTLE_BODY', 'name' => 'Throttle Body', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 256],
            ['code' => 'ACCELERATOR_CABLE', 'name' => 'Accelerator Cable', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 257],
            ['code' => 'ELECTRONIC_ACCELERATOR_PEDAL', 'name' => 'Electronic Accelerator Pedal', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 258],
        ]],
        ['code' => 'SENSOR', 'name' => 'Sensor', 'subcategories' => [
            ['code' => 'FUEL_PRESSURE_SENSOR', 'name' => 'Fuel Pressure Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 259],
            ['code' => 'FUEL_TEMPERATURE_SENSOR', 'name' => 'Fuel Temperature Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 260],
            ['code' => 'FUEL_LEVEL_SENSOR', 'name' => 'Fuel Level Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 261],
        ]],
        ['code' => 'EVAP', 'name' => 'EVAP', 'subcategories' => [
            ['code' => 'CHARCOAL_CANISTER', 'name' => 'Charcoal Canister', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 262],
            ['code' => 'PURGE_VALVE', 'name' => 'Purge Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 263],
            ['code' => 'VAPOR_HOSE', 'name' => 'Vapor Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 264],
        ]],
        ['code' => 'FUEL_ADDITIVE', 'name' => 'Fuel Additive', 'subcategories' => [
            ['code' => 'INJECTOR_CLEANER', 'name' => 'Injector Cleaner', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 265],
            ['code' => 'DIESEL_ADDITIVE', 'name' => 'Diesel Additive', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 266],
            ['code' => 'WATER_REMOVER', 'name' => 'Water Remover', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 267],
        ]],
    ],
    'CG-TRANS' => [
        ['code' => 'MANUAL_GEARBOX', 'name' => 'Manual Gearbox', 'subcategories' => [
            ['code' => 'GEARBOX_ASSEMBLY', 'name' => 'Gearbox Assembly', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 275],
            ['code' => 'INPUT_SHAFT', 'name' => 'Input Shaft', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 276],
            ['code' => 'MAIN_SHAFT', 'name' => 'Main Shaft', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 277],
            ['code' => 'COUNTER_SHAFT', 'name' => 'Counter Shaft', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 278],
            ['code' => 'GEAR_SET', 'name' => 'Gear Set', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 279],
            ['code' => 'REVERSE_GEAR', 'name' => 'Reverse Gear', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 280],
        ]],
        ['code' => 'SYNCHRONIZER', 'name' => 'Synchronizer', 'subcategories' => [
            ['code' => 'SYNCHRONIZER_RING', 'name' => 'Synchronizer Ring', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 281],
            ['code' => 'HUB', 'name' => 'Hub', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 282],
            ['code' => 'SLEEVE', 'name' => 'Sleeve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 283],
        ]],
        ['code' => 'GEAR_SELECTION', 'name' => 'Gear Selection', 'subcategories' => [
            ['code' => 'SHIFT_FORK', 'name' => 'Shift Fork', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 284],
            ['code' => 'SELECTOR_SHAFT', 'name' => 'Selector Shaft', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 285],
            ['code' => 'GEAR_LEVER', 'name' => 'Gear Lever', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 286],
            ['code' => 'SHIFT_CABLE', 'name' => 'Shift Cable', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 287],
            ['code' => 'LINKAGE_BUSH', 'name' => 'Linkage Bush', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 288],
        ]],
        ['code' => 'TRANSMISSION_BEARING', 'name' => 'Transmission Bearing', 'subcategories' => [
            ['code' => 'INPUT_BEARING', 'name' => 'Input Bearing', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 289],
            ['code' => 'OUTPUT_BEARING', 'name' => 'Output Bearing', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 290],
            ['code' => 'NEEDLE_BEARING', 'name' => 'Needle Bearing', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 291],
        ]],
        ['code' => 'TRANSMISSION_SEAL', 'name' => 'Transmission Seal', 'subcategories' => [
            ['code' => 'INPUT_SHAFT_SEAL', 'name' => 'Input Shaft Seal', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 292],
            ['code' => 'OUTPUT_SHAFT_SEAL', 'name' => 'Output Shaft Seal', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 293],
            ['code' => 'SELECTOR_SEAL', 'name' => 'Selector Seal', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 294],
        ]],
        ['code' => 'AUTOMATIC_TRANSMISSION', 'name' => 'Automatic Transmission', 'subcategories' => [
            ['code' => 'AUTOMATIC_TRANSMISSION_ASSEMBLY', 'name' => 'Automatic Transmission Assembly', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 295],
            ['code' => 'PLANETARY_GEAR_SET', 'name' => 'Planetary Gear Set', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 296],
            ['code' => 'CLUTCH_PACK', 'name' => 'Clutch Pack', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 297],
            ['code' => 'BRAKE_BAND', 'name' => 'Brake Band', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 298],
            ['code' => 'VALVE_BODY', 'name' => 'Valve Body', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 299],
            ['code' => 'OIL_PUMP', 'name' => 'Oil Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 300],
            ['code' => 'TRANSMISSION_PAN', 'name' => 'Transmission Pan', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 301],
            ['code' => 'TRANSMISSION_FILTER', 'name' => 'Transmission Filter', 'description' => null, 'item_types' => ['SPARE_PART', 'CONSUMABLE'], 'basis' => 'derived_filter', 'line' => 302],
        ]],
        ['code' => 'AUTOMATIC_CONTROL', 'name' => 'Automatic Control', 'subcategories' => [
            ['code' => 'SHIFT_SOLENOID', 'name' => 'Shift Solenoid', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 303],
            ['code' => 'PRESSURE_SOLENOID', 'name' => 'Pressure Solenoid', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 304],
            ['code' => 'SPEED_SENSOR', 'name' => 'Speed Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 305],
            ['code' => 'RANGE_SENSOR', 'name' => 'Range Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 306],
        ]],
        ['code' => 'CVT', 'name' => 'CVT', 'subcategories' => [
            ['code' => 'CVT_PULLEY', 'name' => 'CVT Pulley', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 307],
            ['code' => 'CVT_BELT', 'name' => 'CVT Belt', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 308],
            ['code' => 'CVT_CHAIN', 'name' => 'CVT Chain', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 309],
            ['code' => 'STEP_MOTOR', 'name' => 'Step Motor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 310],
            ['code' => 'VALVE_BODY', 'name' => 'Valve Body', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 311],
        ]],
        ['code' => 'DUAL_CLUTCH', 'name' => 'Dual Clutch', 'subcategories' => [
            ['code' => 'DCT_CLUTCH_PACK', 'name' => 'DCT Clutch Pack', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 312],
            ['code' => 'MECHATRONIC_UNIT', 'name' => 'Mechatronic Unit', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 313],
        ]],
        ['code' => 'TRANSFER_CASE', 'name' => 'Transfer Case', 'subcategories' => [
            ['code' => 'TRANSFER_CASE_ASSEMBLY', 'name' => 'Transfer Case Assembly', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 314],
            ['code' => 'TRANSFER_GEAR', 'name' => 'Transfer Gear', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 315],
            ['code' => 'SHIFT_MOTOR', 'name' => 'Shift Motor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 316],
        ]],
        ['code' => 'LUBRICANT', 'name' => 'Lubricant', 'subcategories' => [
            ['code' => 'MTF', 'name' => 'MTF', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 317],
            ['code' => 'ATF', 'name' => 'ATF', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 318],
            ['code' => 'CVT_FLUID', 'name' => 'CVT Fluid', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 319],
            ['code' => 'DCT_FLUID', 'name' => 'DCT Fluid', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 320],
        ]],
    ],
    'CG-ENGINE-EXHAUST' => [
        ['code' => 'EXHAUST_MANIFOLD', 'name' => 'Exhaust Manifold', 'subcategories' => [
            ['code' => 'EXHAUST_MANIFOLD', 'name' => 'Exhaust Manifold', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 328],
            ['code' => 'MANIFOLD_GASKET', 'name' => 'Manifold Gasket', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 329],
        ]],
        ['code' => 'TURBO_EXHAUST', 'name' => 'Turbo Exhaust', 'subcategories' => [
            ['code' => 'TURBO_OUTLET_PIPE', 'name' => 'Turbo Outlet Pipe', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 330],
        ]],
        ['code' => 'EXHAUST_PIPE', 'name' => 'Exhaust Pipe', 'subcategories' => [
            ['code' => 'FRONT_PIPE', 'name' => 'Front Pipe', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 331],
            ['code' => 'CENTER_PIPE', 'name' => 'Center Pipe', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 332],
            ['code' => 'TAIL_PIPE', 'name' => 'Tail Pipe', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 333],
        ]],
        ['code' => 'FLEXIBLE_JOINT', 'name' => 'Flexible Joint', 'subcategories' => [
            ['code' => 'EXHAUST_FLEX_PIPE', 'name' => 'Exhaust Flex Pipe', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 334],
        ]],
        ['code' => 'MUFFLER', 'name' => 'Muffler', 'subcategories' => [
            ['code' => 'FRONT_MUFFLER', 'name' => 'Front Muffler', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 335],
            ['code' => 'CENTER_MUFFLER', 'name' => 'Center Muffler', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 336],
            ['code' => 'REAR_MUFFLER', 'name' => 'Rear Muffler', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 337],
        ]],
        ['code' => 'CATALYTIC_CONVERTER', 'name' => 'Catalytic Converter', 'subcategories' => [
            ['code' => 'THREE_WAY_CATALYST', 'name' => 'Three-Way Catalyst', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 338],
            ['code' => 'DIESEL_OXIDATION_CATALYST', 'name' => 'Diesel Oxidation Catalyst', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 339],
        ]],
        ['code' => 'DPF', 'name' => 'DPF', 'subcategories' => [
            ['code' => 'DIESEL_PARTICULATE_FILTER', 'name' => 'Diesel Particulate Filter', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 340],
            ['code' => 'DIFFERENTIAL_PRESSURE_PIPE', 'name' => 'Differential Pressure Pipe', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 341],
        ]],
        ['code' => 'SCR', 'name' => 'SCR', 'subcategories' => [
            ['code' => 'SCR_CATALYST', 'name' => 'SCR Catalyst', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 342],
            ['code' => 'DEF_INJECTOR', 'name' => 'DEF Injector', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 343],
            ['code' => 'DEF_PUMP', 'name' => 'DEF Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 344],
            ['code' => 'DEF_TANK', 'name' => 'DEF Tank', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 345],
            ['code' => 'DEF_HEATER', 'name' => 'DEF Heater', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 346],
        ]],
        ['code' => 'EGR', 'name' => 'EGR', 'subcategories' => [
            ['code' => 'EGR_VALVE', 'name' => 'EGR Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 347],
            ['code' => 'EGR_COOLER', 'name' => 'EGR Cooler', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 348],
            ['code' => 'EGR_PIPE', 'name' => 'EGR Pipe', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 349],
            ['code' => 'EGR_GASKET', 'name' => 'EGR Gasket', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 350],
        ]],
        ['code' => 'SENSOR', 'name' => 'Sensor', 'subcategories' => [
            ['code' => 'OXYGEN_SENSOR', 'name' => 'Oxygen Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 351],
            ['code' => 'NOX_SENSOR', 'name' => 'NOx Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 352],
            ['code' => 'EXHAUST_GAS_TEMPERATURE_SENSOR', 'name' => 'Exhaust Gas Temperature Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 353],
            ['code' => 'DPF_PRESSURE_SENSOR', 'name' => 'DPF Pressure Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 354],
        ]],
        ['code' => 'MOUNTING', 'name' => 'Mounting', 'subcategories' => [
            ['code' => 'EXHAUST_HANGER', 'name' => 'Exhaust Hanger', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 355],
            ['code' => 'RUBBER_MOUNT', 'name' => 'Rubber Mount', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 356],
            ['code' => 'EXHAUST_BRACKET', 'name' => 'Exhaust Bracket', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 357],
        ]],
        ['code' => 'CLAMP', 'name' => 'Clamp', 'subcategories' => [
            ['code' => 'EXHAUST_CLAMP', 'name' => 'Exhaust Clamp', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 358],
        ]],
        ['code' => 'CONSUMABLE', 'name' => 'Consumable', 'subcategories' => [
            ['code' => 'DEF_ADBLUE', 'name' => 'DEF / AdBlue', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 359],
            ['code' => 'DPF_CLEANER', 'name' => 'DPF Cleaner', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 360],
        ]],
    ],
    'CG-STEER' => [
        ['code' => 'STEERING_WHEEL', 'name' => 'Steering Wheel', 'subcategories' => [
            ['code' => 'STEERING_WHEEL', 'name' => 'Steering Wheel', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 368],
        ]],
        ['code' => 'STEERING_COLUMN', 'name' => 'Steering Column', 'subcategories' => [
            ['code' => 'COLUMN_ASSEMBLY', 'name' => 'Column Assembly', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 369],
            ['code' => 'INTERMEDIATE_SHAFT', 'name' => 'Intermediate Shaft', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 370],
            ['code' => 'UNIVERSAL_JOINT', 'name' => 'Universal Joint', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 371],
            ['code' => 'COLUMN_BEARING', 'name' => 'Column Bearing', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 372],
        ]],
        ['code' => 'STEERING_GEAR', 'name' => 'Steering Gear', 'subcategories' => [
            ['code' => 'STEERING_RACK', 'name' => 'Steering Rack', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 373],
            ['code' => 'STEERING_GEAR_BOX', 'name' => 'Steering Gear Box', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 374],
            ['code' => 'RACK_END', 'name' => 'Rack End', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 375],
            ['code' => 'RACK_BOOT', 'name' => 'Rack Boot', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 376],
        ]],
        ['code' => 'STEERING_LINKAGE', 'name' => 'Steering Linkage', 'subcategories' => [
            ['code' => 'INNER_TIE_ROD', 'name' => 'Inner Tie Rod', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 377],
            ['code' => 'OUTER_TIE_ROD', 'name' => 'Outer Tie Rod', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 378],
            ['code' => 'DRAG_LINK', 'name' => 'Drag Link', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 379],
            ['code' => 'CENTER_LINK', 'name' => 'Center Link', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 380],
            ['code' => 'PITMAN_ARM', 'name' => 'Pitman Arm', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 381],
            ['code' => 'IDLER_ARM', 'name' => 'Idler Arm', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 382],
        ]],
        ['code' => 'STEERING_KNUCKLE', 'name' => 'Steering Knuckle', 'subcategories' => [
            ['code' => 'STEERING_KNUCKLE', 'name' => 'Steering Knuckle', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 383],
            ['code' => 'KING_PIN', 'name' => 'King Pin', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 384],
            ['code' => 'KING_PIN_BUSH', 'name' => 'King Pin Bush', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 385],
        ]],
        ['code' => 'HYDRAULIC_STEERING', 'name' => 'Hydraulic Steering', 'subcategories' => [
            ['code' => 'POWER_STEERING_PUMP', 'name' => 'Power Steering Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 386],
            ['code' => 'RESERVOIR', 'name' => 'Reservoir', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 387],
            ['code' => 'PRESSURE_HOSE', 'name' => 'Pressure Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 388],
            ['code' => 'RETURN_HOSE', 'name' => 'Return Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 389],
            ['code' => 'STEERING_OIL_COOLER', 'name' => 'Steering Oil Cooler', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 390],
        ]],
        ['code' => 'ELECTRIC_STEERING', 'name' => 'Electric Steering', 'subcategories' => [
            ['code' => 'EPS_MOTOR', 'name' => 'EPS Motor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 391],
            ['code' => 'EPS_ECU', 'name' => 'EPS ECU', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 392],
            ['code' => 'TORQUE_SENSOR', 'name' => 'Torque Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 393],
            ['code' => 'STEERING_ANGLE_SENSOR', 'name' => 'Steering Angle Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 394],
        ]],
        ['code' => 'SEAL', 'name' => 'Seal', 'subcategories' => [
            ['code' => 'STEERING_RACK_SEAL_KIT', 'name' => 'Steering Rack Seal Kit', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 395],
        ]],
        ['code' => 'FLUID', 'name' => 'Fluid', 'subcategories' => [
            ['code' => 'POWER_STEERING_FLUID', 'name' => 'Power Steering Fluid', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 396],
        ]],
    ],
    'CG-AXLE' => [
        ['code' => 'PROPELLER_SHAFT', 'name' => 'Propeller Shaft', 'subcategories' => [
            ['code' => 'PROPELLER_SHAFT', 'name' => 'Propeller Shaft', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 404],
            ['code' => 'SLIP_YOKE', 'name' => 'Slip Yoke', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 405],
            ['code' => 'FLANGE_YOKE', 'name' => 'Flange Yoke', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 406],
        ]],
        ['code' => 'UNIVERSAL_JOINT', 'name' => 'Universal Joint', 'subcategories' => [
            ['code' => 'UNIVERSAL_JOINT', 'name' => 'Universal Joint', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 407],
        ]],
        ['code' => 'CENTER_BEARING', 'name' => 'Center Bearing', 'subcategories' => [
            ['code' => 'PROPELLER_CENTER_BEARING', 'name' => 'Propeller Center Bearing', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 408],
        ]],
        ['code' => 'FRONT_AXLE', 'name' => 'Front Axle', 'subcategories' => [
            ['code' => 'AXLE_HOUSING', 'name' => 'Axle Housing', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 409],
        ]],
        ['code' => 'REAR_AXLE', 'name' => 'Rear Axle', 'subcategories' => [
            ['code' => 'AXLE_HOUSING', 'name' => 'Axle Housing', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 410],
        ]],
        ['code' => 'AXLE_SHAFT', 'name' => 'Axle Shaft', 'subcategories' => [
            ['code' => 'LEFT_AXLE_SHAFT', 'name' => 'Left Axle Shaft', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 411],
            ['code' => 'RIGHT_AXLE_SHAFT', 'name' => 'Right Axle Shaft', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 412],
        ]],
        ['code' => 'DIFFERENTIAL', 'name' => 'Differential', 'subcategories' => [
            ['code' => 'DIFFERENTIAL_CARRIER', 'name' => 'Differential Carrier', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 413],
            ['code' => 'CROWN_WHEEL', 'name' => 'Crown Wheel', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 414],
            ['code' => 'PINION_GEAR', 'name' => 'Pinion Gear', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 415],
            ['code' => 'SPIDER_GEAR', 'name' => 'Spider Gear', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 416],
            ['code' => 'SIDE_GEAR', 'name' => 'Side Gear', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 417],
            ['code' => 'DIFFERENTIAL_CASE', 'name' => 'Differential Case', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 418],
            ['code' => 'PINION_BEARING', 'name' => 'Pinion Bearing', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 419],
            ['code' => 'CARRIER_BEARING', 'name' => 'Carrier Bearing', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 420],
            ['code' => 'PINION_SEAL', 'name' => 'Pinion Seal', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 421],
        ]],
        ['code' => 'LIMITED_SLIP', 'name' => 'Limited Slip', 'subcategories' => [
            ['code' => 'LSD_CLUTCH', 'name' => 'LSD Clutch', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 422],
            ['code' => 'LSD_CARRIER', 'name' => 'LSD Carrier', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 423],
        ]],
        ['code' => 'CV_DRIVE', 'name' => 'CV Drive', 'subcategories' => [
            ['code' => 'CV_JOINT', 'name' => 'CV Joint', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 424],
            ['code' => 'CV_BOOT', 'name' => 'CV Boot', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 425],
            ['code' => 'DRIVE_SHAFT', 'name' => 'Drive Shaft', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 426],
        ]],
        ['code' => 'WHEEL_HUB', 'name' => 'Wheel Hub', 'subcategories' => [
            ['code' => 'HUB_ASSEMBLY', 'name' => 'Hub Assembly', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 427],
            ['code' => 'WHEEL_BEARING', 'name' => 'Wheel Bearing', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 428],
            ['code' => 'HUB_SEAL', 'name' => 'Hub Seal', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 429],
            ['code' => 'WHEEL_STUD', 'name' => 'Wheel Stud', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 430],
        ]],
        ['code' => 'FINAL_DRIVE', 'name' => 'Final Drive', 'subcategories' => [
            ['code' => 'PLANETARY_FINAL_DRIVE', 'name' => 'Planetary Final Drive', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 431],
            ['code' => 'FINAL_DRIVE_MOTOR', 'name' => 'Final Drive Motor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 432],
            ['code' => 'REDUCTION_GEAR', 'name' => 'Reduction Gear', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 433],
        ]],
        ['code' => 'AXLE_BREATHER', 'name' => 'Axle Breather', 'subcategories' => [
            ['code' => 'BREATHER', 'name' => 'Breather', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 434],
        ]],
        ['code' => 'LUBRICANT', 'name' => 'Lubricant', 'subcategories' => [
            ['code' => 'AXLE_DIFFERENTIAL_OIL', 'name' => 'Axle/Differential Oil', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 435],
        ]],
    ],
    'CG-FRAME' => [
        ['code' => 'MAIN_FRAME', 'name' => 'Main Frame', 'subcategories' => [
            ['code' => 'CHASSIS_RAIL', 'name' => 'Chassis Rail', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 443],
            ['code' => 'MAIN_FRAME_ASSEMBLY', 'name' => 'Main Frame Assembly', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 444],
        ]],
        ['code' => 'CROSS_MEMBER', 'name' => 'Cross Member', 'subcategories' => [
            ['code' => 'FRONT_CROSS_MEMBER', 'name' => 'Front Cross Member', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 445],
            ['code' => 'CENTER_CROSS_MEMBER', 'name' => 'Center Cross Member', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 446],
            ['code' => 'REAR_CROSS_MEMBER', 'name' => 'Rear Cross Member', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 447],
        ]],
        ['code' => 'SUBFRAME', 'name' => 'Subframe', 'subcategories' => [
            ['code' => 'FRONT_SUBFRAME', 'name' => 'Front Subframe', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 448],
            ['code' => 'REAR_SUBFRAME', 'name' => 'Rear Subframe', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 449],
        ]],
        ['code' => 'FRAME_MOUNT', 'name' => 'Frame Mount', 'subcategories' => [
            ['code' => 'BODY_MOUNT', 'name' => 'Body Mount', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 450],
            ['code' => 'CAB_MOUNT', 'name' => 'Cab Mount', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 451],
            ['code' => 'EQUIPMENT_MOUNT', 'name' => 'Equipment Mount', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 452],
        ]],
        ['code' => 'GUARD', 'name' => 'Guard', 'subcategories' => [
            ['code' => 'ENGINE_GUARD', 'name' => 'Engine Guard', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 453],
            ['code' => 'TRANSMISSION_GUARD', 'name' => 'Transmission Guard', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 454],
            ['code' => 'FUEL_TANK_GUARD', 'name' => 'Fuel Tank Guard', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 455],
            ['code' => 'SIDE_GUARD', 'name' => 'Side Guard', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 456],
            ['code' => 'UNDERRUN_GUARD', 'name' => 'Underrun Guard', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 457],
            ['code' => 'SPLASH_SHIELD', 'name' => 'Splash Shield', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 458],
        ]],
        ['code' => 'BOGIE', 'name' => 'Bogie', 'subcategories' => [
            ['code' => 'BOGIE_FRAME', 'name' => 'Bogie Frame', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 459],
            ['code' => 'BOGIE_BEAM', 'name' => 'Bogie Beam', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 460],
            ['code' => 'BOGIE_PIN', 'name' => 'Bogie Pin', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 461],
            ['code' => 'BOGIE_BEARING', 'name' => 'Bogie Bearing', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 462],
            ['code' => 'BOGIE_BUSH', 'name' => 'Bogie Bush', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 463],
        ]],
        ['code' => 'TOW_SYSTEM', 'name' => 'Tow System', 'subcategories' => [
            ['code' => 'TOW_HOOK', 'name' => 'Tow Hook', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 464],
            ['code' => 'TOW_EYE', 'name' => 'Tow Eye', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 465],
            ['code' => 'DRAWBAR', 'name' => 'Drawbar', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 466],
        ]],
        ['code' => 'HITCH', 'name' => 'Hitch', 'subcategories' => [
            ['code' => 'PINTLE_HOOK', 'name' => 'Pintle Hook', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 467],
            ['code' => 'FIFTH_WHEEL', 'name' => 'Fifth Wheel', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 468],
        ]],
        ['code' => 'FASTENER', 'name' => 'Fastener', 'subcategories' => [
            ['code' => 'FRAME_BOLT', 'name' => 'Frame Bolt', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 469],
            ['code' => 'HIGH_TENSILE_NUT', 'name' => 'High-Tensile Nut', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 470],
            ['code' => 'WASHER', 'name' => 'Washer', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 471],
        ]],
        ['code' => 'CORROSION_PROTECTION', 'name' => 'Corrosion Protection', 'subcategories' => [
            ['code' => 'ANTI_RUST_COATING', 'name' => 'Anti-Rust Coating', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 472],
        ]],
        ['code' => 'COATING', 'name' => 'Coating', 'subcategories' => [
            ['code' => 'PRIMER', 'name' => 'Primer', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 473],
            ['code' => 'CHASSIS_PAINT', 'name' => 'Chassis Paint', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 474],
        ]],
    ],
    'CG-ELEC' => [
        ['code' => 'BATTERY', 'name' => 'Battery', 'subcategories' => [
            ['code' => 'STARTER_BATTERY', 'name' => 'Starter Battery', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 484],
            ['code' => 'AUXILIARY_BATTERY', 'name' => 'Auxiliary Battery', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 485],
            ['code' => 'BATTERY_TERMINAL', 'name' => 'Battery Terminal', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 486],
            ['code' => 'BATTERY_CABLE', 'name' => 'Battery Cable', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 487],
            ['code' => 'BATTERY_CLAMP', 'name' => 'Battery Clamp', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 488],
            ['code' => 'BATTERY_TRAY', 'name' => 'Battery Tray', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 489],
        ]],
        ['code' => 'CHARGING', 'name' => 'Charging', 'subcategories' => [
            ['code' => 'ALTERNATOR', 'name' => 'Alternator', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 490],
            ['code' => 'VOLTAGE_REGULATOR', 'name' => 'Voltage Regulator', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 491],
            ['code' => 'ALTERNATOR_PULLEY', 'name' => 'Alternator Pulley', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 492],
            ['code' => 'ALTERNATOR_BEARING', 'name' => 'Alternator Bearing', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 493],
        ]],
        ['code' => 'STARTING', 'name' => 'Starting', 'subcategories' => [
            ['code' => 'STARTER_MOTOR', 'name' => 'Starter Motor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 494],
            ['code' => 'STARTER_SOLENOID', 'name' => 'Starter Solenoid', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 495],
            ['code' => 'STARTER_RELAY', 'name' => 'Starter Relay', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 496],
            ['code' => 'STARTER_BENDIX', 'name' => 'Starter Bendix', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 497],
        ]],
        ['code' => 'WIRING_HARNESS', 'name' => 'Wiring Harness', 'subcategories' => [
            ['code' => 'ENGINE_HARNESS', 'name' => 'Engine Harness', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 498],
            ['code' => 'CABIN_HARNESS', 'name' => 'Cabin Harness', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 499],
            ['code' => 'CHASSIS_HARNESS', 'name' => 'Chassis Harness', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 500],
            ['code' => 'DOOR_HARNESS', 'name' => 'Door Harness', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 501],
        ]],
        ['code' => 'CABLE', 'name' => 'Cable', 'subcategories' => [
            ['code' => 'POWER_CABLE', 'name' => 'Power Cable', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 502],
            ['code' => 'GROUND_CABLE', 'name' => 'Ground Cable', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 503],
        ]],
        ['code' => 'CONNECTOR', 'name' => 'Connector', 'subcategories' => [
            ['code' => 'ELECTRICAL_CONNECTOR', 'name' => 'Electrical Connector', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 504],
            ['code' => 'TERMINAL', 'name' => 'Terminal', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 505],
            ['code' => 'CONNECTOR_HOUSING', 'name' => 'Connector Housing', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 506],
            ['code' => 'WEATHER_SEAL', 'name' => 'Weather Seal', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 507],
        ]],
        ['code' => 'FUSE', 'name' => 'Fuse', 'subcategories' => [
            ['code' => 'MINI_FUSE', 'name' => 'Mini Fuse', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 508],
            ['code' => 'STANDARD_FUSE', 'name' => 'Standard Fuse', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 509],
            ['code' => 'MAXI_FUSE', 'name' => 'Maxi Fuse', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 510],
            ['code' => 'CARTRIDGE_FUSE', 'name' => 'Cartridge Fuse', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 511],
            ['code' => 'FUSIBLE_LINK', 'name' => 'Fusible Link', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 512],
        ]],
        ['code' => 'RELAY', 'name' => 'Relay', 'subcategories' => [
            ['code' => 'MICRO_RELAY', 'name' => 'Micro Relay', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 513],
            ['code' => 'STANDARD_RELAY', 'name' => 'Standard Relay', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 514],
            ['code' => 'POWER_RELAY', 'name' => 'Power Relay', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 515],
        ]],
        ['code' => 'SWITCH', 'name' => 'Switch', 'subcategories' => [
            ['code' => 'IGNITION_SWITCH', 'name' => 'Ignition Switch', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 516],
            ['code' => 'BRAKE_SWITCH', 'name' => 'Brake Switch', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 517],
            ['code' => 'CLUTCH_SWITCH', 'name' => 'Clutch Switch', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 518],
            ['code' => 'REVERSE_SWITCH', 'name' => 'Reverse Switch', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 519],
            ['code' => 'DOOR_SWITCH', 'name' => 'Door Switch', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 520],
            ['code' => 'COMBINATION_SWITCH', 'name' => 'Combination Switch', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 521],
        ]],
        ['code' => 'ENGINE_SENSOR', 'name' => 'Engine Sensor', 'subcategories' => [
            ['code' => 'CKP_SENSOR', 'name' => 'CKP Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 522],
            ['code' => 'CMP_SENSOR', 'name' => 'CMP Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 523],
            ['code' => 'MAP_SENSOR', 'name' => 'MAP Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 524],
            ['code' => 'MAF_SENSOR', 'name' => 'MAF Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 525],
            ['code' => 'TPS_SENSOR', 'name' => 'TPS Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 526],
            ['code' => 'KNOCK_SENSOR', 'name' => 'Knock Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 527],
        ]],
        ['code' => 'TEMPERATURE_SENSOR', 'name' => 'Temperature Sensor', 'subcategories' => [
            ['code' => 'COOLANT_SENSOR', 'name' => 'Coolant Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 528],
            ['code' => 'INTAKE_SENSOR', 'name' => 'Intake Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 529],
        ]],
        ['code' => 'PRESSURE_SENSOR', 'name' => 'Pressure Sensor', 'subcategories' => [
            ['code' => 'OIL_PRESSURE_SENSOR', 'name' => 'Oil Pressure Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 530],
            ['code' => 'FUEL_PRESSURE_SENSOR', 'name' => 'Fuel Pressure Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 531],
            ['code' => 'BOOST_PRESSURE_SENSOR', 'name' => 'Boost Pressure Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 532],
        ]],
        ['code' => 'POSITION_SENSOR', 'name' => 'Position Sensor', 'subcategories' => [
            ['code' => 'ACCELERATOR_POSITION_SENSOR', 'name' => 'Accelerator Position Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 533],
            ['code' => 'STEERING_ANGLE_SENSOR', 'name' => 'Steering Angle Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 534],
            ['code' => 'RIDE_HEIGHT_SENSOR', 'name' => 'Ride Height Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 535],
        ]],
        ['code' => 'SPEED_SENSOR', 'name' => 'Speed Sensor', 'subcategories' => [
            ['code' => 'VEHICLE_SPEED_SENSOR', 'name' => 'Vehicle Speed Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 536],
            ['code' => 'WHEEL_SPEED_SENSOR', 'name' => 'Wheel Speed Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 537],
        ]],
        ['code' => 'ECU', 'name' => 'ECU', 'subcategories' => [
            ['code' => 'ENGINE_CONTROL_MODULE', 'name' => 'Engine Control Module', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 538],
            ['code' => 'TRANSMISSION_CONTROL_MODULE', 'name' => 'Transmission Control Module', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 539],
            ['code' => 'BODY_CONTROL_MODULE', 'name' => 'Body Control Module', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 540],
            ['code' => 'ABS_MODULE', 'name' => 'ABS Module', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 541],
            ['code' => 'AIRBAG_MODULE', 'name' => 'Airbag Module', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 542],
            ['code' => 'GATEWAY_MODULE', 'name' => 'Gateway Module', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 543],
        ]],
        ['code' => 'ACTUATOR', 'name' => 'Actuator', 'subcategories' => [
            ['code' => 'SOLENOID', 'name' => 'Solenoid', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 544],
            ['code' => 'ELECTRIC_MOTOR', 'name' => 'Electric Motor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 545],
        ]],
        ['code' => 'LIGHTING', 'name' => 'Lighting', 'subcategories' => [
            ['code' => 'HEADLAMP_ASSEMBLY', 'name' => 'Headlamp Assembly', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 546],
            ['code' => 'TAIL_LAMP', 'name' => 'Tail Lamp', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 547],
            ['code' => 'FOG_LAMP', 'name' => 'Fog Lamp', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 548],
            ['code' => 'TURN_SIGNAL', 'name' => 'Turn Signal', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 549],
            ['code' => 'SIDE_MARKER', 'name' => 'Side Marker', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 550],
            ['code' => 'WORK_LAMP', 'name' => 'Work Lamp', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 551],
            ['code' => 'BEACON', 'name' => 'Beacon', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 552],
        ]],
        ['code' => 'BULB', 'name' => 'Bulb', 'subcategories' => [
            ['code' => 'HALOGEN_BULB', 'name' => 'Halogen Bulb', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 553],
            ['code' => 'LED_MODULE', 'name' => 'LED Module', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 554],
        ]],
        ['code' => 'HORN', 'name' => 'Horn', 'subcategories' => [
            ['code' => 'HORN', 'name' => 'Horn', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 555],
        ]],
        ['code' => 'WIPER', 'name' => 'Wiper', 'subcategories' => [
            ['code' => 'WIPER_MOTOR', 'name' => 'Wiper Motor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 556],
            ['code' => 'WIPER_LINKAGE', 'name' => 'Wiper Linkage', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 557],
            ['code' => 'WIPER_ARM', 'name' => 'Wiper Arm', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 558],
            ['code' => 'WIPER_BLADE', 'name' => 'Wiper Blade', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 559],
        ]],
        ['code' => 'WASHER', 'name' => 'Washer', 'subcategories' => [
            ['code' => 'WASHER_PUMP', 'name' => 'Washer Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 560],
            ['code' => 'WASHER_RESERVOIR', 'name' => 'Washer Reservoir', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 561],
            ['code' => 'WASHER_NOZZLE', 'name' => 'Washer Nozzle', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 562],
        ]],
        ['code' => 'INSTRUMENT', 'name' => 'Instrument', 'subcategories' => [
            ['code' => 'INSTRUMENT_CLUSTER', 'name' => 'Instrument Cluster', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 563],
            ['code' => 'GAUGE', 'name' => 'Gauge', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 564],
        ]],
        ['code' => 'ACCESSORY_POWER', 'name' => 'Accessory Power', 'subcategories' => [
            ['code' => 'CIGARETTE_SOCKET', 'name' => 'Cigarette Socket', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 565],
            ['code' => 'USB_CHARGER', 'name' => 'USB Charger', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 566],
        ]],
        ['code' => 'CONSUMABLE', 'name' => 'Consumable', 'subcategories' => [
            ['code' => 'CABLE_TIE', 'name' => 'Cable Tie', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 567],
            ['code' => 'ELECTRICAL_TAPE', 'name' => 'Electrical Tape', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 568],
            ['code' => 'HEAT_SHRINK', 'name' => 'Heat Shrink', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 569],
            ['code' => 'CONTACT_CLEANER', 'name' => 'Contact Cleaner', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 570],
        ]],
    ],
    'CG-BRAKE' => [
        ['code' => 'DISC_BRAKE', 'name' => 'Disc Brake', 'subcategories' => [
            ['code' => 'BRAKE_PAD', 'name' => 'Brake Pad', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 578],
            ['code' => 'BRAKE_DISC_ROTOR', 'name' => 'Brake Disc / Rotor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 579],
            ['code' => 'BRAKE_CALIPER', 'name' => 'Brake Caliper', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 580],
            ['code' => 'CALIPER_PISTON', 'name' => 'Caliper Piston', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 581],
            ['code' => 'CALIPER_SEAL_KIT', 'name' => 'Caliper Seal Kit', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 582],
            ['code' => 'CALIPER_GUIDE_PIN', 'name' => 'Caliper Guide Pin', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 583],
        ]],
        ['code' => 'DRUM_BRAKE', 'name' => 'Drum Brake', 'subcategories' => [
            ['code' => 'BRAKE_SHOE', 'name' => 'Brake Shoe', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 584],
            ['code' => 'BRAKE_DRUM', 'name' => 'Brake Drum', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 585],
            ['code' => 'WHEEL_CYLINDER', 'name' => 'Wheel Cylinder', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 586],
            ['code' => 'RETURN_SPRING', 'name' => 'Return Spring', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 587],
            ['code' => 'ADJUSTER', 'name' => 'Adjuster', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 588],
        ]],
        ['code' => 'HYDRAULIC_BRAKE', 'name' => 'Hydraulic Brake', 'subcategories' => [
            ['code' => 'MASTER_CYLINDER', 'name' => 'Master Cylinder', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 589],
            ['code' => 'RESERVOIR', 'name' => 'Reservoir', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 590],
            ['code' => 'BRAKE_HOSE', 'name' => 'Brake Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 591],
            ['code' => 'BRAKE_PIPE', 'name' => 'Brake Pipe', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 592],
            ['code' => 'PROPORTIONING_VALVE', 'name' => 'Proportioning Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 593],
        ]],
        ['code' => 'BRAKE_BOOSTER', 'name' => 'Brake Booster', 'subcategories' => [
            ['code' => 'VACUUM_BOOSTER', 'name' => 'Vacuum Booster', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 594],
            ['code' => 'VACUUM_PUMP', 'name' => 'Vacuum Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 595],
            ['code' => 'VACUUM_HOSE', 'name' => 'Vacuum Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 596],
        ]],
        ['code' => 'ABS', 'name' => 'ABS', 'subcategories' => [
            ['code' => 'ABS_MODULATOR', 'name' => 'ABS Modulator', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 597],
            ['code' => 'ABS_PUMP', 'name' => 'ABS Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 598],
            ['code' => 'WHEEL_SPEED_SENSOR', 'name' => 'Wheel Speed Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 599],
            ['code' => 'TONE_RING', 'name' => 'Tone Ring', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 600],
            ['code' => 'ABS_ECU', 'name' => 'ABS ECU', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 601],
        ]],
        ['code' => 'PARKING_BRAKE', 'name' => 'Parking Brake', 'subcategories' => [
            ['code' => 'PARKING_BRAKE_CABLE', 'name' => 'Parking Brake Cable', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 602],
            ['code' => 'PARKING_BRAKE_LEVER', 'name' => 'Parking Brake Lever', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 603],
            ['code' => 'PARKING_BRAKE_SHOE', 'name' => 'Parking Brake Shoe', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 604],
        ]],
        ['code' => 'EPB', 'name' => 'EPB', 'subcategories' => [
            ['code' => 'ELECTRIC_PARKING_BRAKE_MOTOR', 'name' => 'Electric Parking Brake Motor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 605],
            ['code' => 'EPB_MODULE', 'name' => 'EPB Module', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 606],
        ]],
        ['code' => 'AIR_BRAKE', 'name' => 'Air Brake', 'subcategories' => [
            ['code' => 'BRAKE_CHAMBER', 'name' => 'Brake Chamber', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 607],
            ['code' => 'SPRING_BRAKE_CHAMBER', 'name' => 'Spring Brake Chamber', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 608],
            ['code' => 'SLACK_ADJUSTER', 'name' => 'Slack Adjuster', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 609],
            ['code' => 'S_CAM', 'name' => 'S-Cam', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 610],
            ['code' => 'BRAKE_LINING', 'name' => 'Brake Lining', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 611],
            ['code' => 'RELAY_VALVE', 'name' => 'Relay Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 612],
            ['code' => 'FOOT_BRAKE_VALVE', 'name' => 'Foot Brake Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 613],
            ['code' => 'QUICK_RELEASE_VALVE', 'name' => 'Quick Release Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 614],
        ]],
        ['code' => 'RETARDER', 'name' => 'Retarder', 'subcategories' => [
            ['code' => 'HYDRAULIC_RETARDER', 'name' => 'Hydraulic Retarder', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 615],
            ['code' => 'ELECTRIC_RETARDER', 'name' => 'Electric Retarder', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 616],
        ]],
        ['code' => 'FLUID', 'name' => 'Fluid', 'subcategories' => [
            ['code' => 'BRAKE_FLUID', 'name' => 'Brake Fluid', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 617],
        ]],
        ['code' => 'CHEMICAL', 'name' => 'Chemical', 'subcategories' => [
            ['code' => 'BRAKE_CLEANER', 'name' => 'Brake Cleaner', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 618],
        ]],
        ['code' => 'LUBRICANT', 'name' => 'Lubricant', 'subcategories' => [
            ['code' => 'BRAKE_GREASE', 'name' => 'Brake Grease', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 619],
        ]],
    ],
    'CG-SUSP' => [
        ['code' => 'SHOCK_ABSORBER', 'name' => 'Shock Absorber', 'subcategories' => [
            ['code' => 'FRONT_SHOCK', 'name' => 'Front Shock', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 627],
            ['code' => 'REAR_SHOCK', 'name' => 'Rear Shock', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 628],
        ]],
        ['code' => 'STRUT', 'name' => 'Strut', 'subcategories' => [
            ['code' => 'MACPHERSON_STRUT', 'name' => 'MacPherson Strut', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 629],
            ['code' => 'STRUT_MOUNT', 'name' => 'Strut Mount', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 630],
            ['code' => 'STRUT_BEARING', 'name' => 'Strut Bearing', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 631],
        ]],
        ['code' => 'COIL_SPRING', 'name' => 'Coil Spring', 'subcategories' => [
            ['code' => 'FRONT_COIL_SPRING', 'name' => 'Front Coil Spring', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 632],
            ['code' => 'REAR_COIL_SPRING', 'name' => 'Rear Coil Spring', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 633],
        ]],
        ['code' => 'LEAF_SPRING', 'name' => 'Leaf Spring', 'subcategories' => [
            ['code' => 'MAIN_LEAF', 'name' => 'Main Leaf', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 634],
            ['code' => 'HELPER_LEAF', 'name' => 'Helper Leaf', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 635],
            ['code' => 'SPRING_PACK', 'name' => 'Spring Pack', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 636],
            ['code' => 'CENTER_BOLT', 'name' => 'Center Bolt', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 637],
            ['code' => 'SPRING_CLIP', 'name' => 'Spring Clip', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 638],
            ['code' => 'SPRING_EYE_BUSH', 'name' => 'Spring Eye Bush', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 639],
            ['code' => 'SHACKLE', 'name' => 'Shackle', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 640],
            ['code' => 'U_BOLT', 'name' => 'U-Bolt', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 641],
        ]],
        ['code' => 'AIR_SUSPENSION', 'name' => 'Air Suspension', 'subcategories' => [
            ['code' => 'AIR_SPRING', 'name' => 'Air Spring', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 642],
            ['code' => 'HEIGHT_CONTROL_VALVE', 'name' => 'Height Control Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 643],
            ['code' => 'AIR_SUSPENSION_COMPRESSOR', 'name' => 'Air Suspension Compressor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 644],
            ['code' => 'AIR_SUSPENSION_ECU', 'name' => 'Air Suspension ECU', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 645],
        ]],
        ['code' => 'CONTROL_ARM', 'name' => 'Control Arm', 'subcategories' => [
            ['code' => 'UPPER_CONTROL_ARM', 'name' => 'Upper Control Arm', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 646],
            ['code' => 'LOWER_CONTROL_ARM', 'name' => 'Lower Control Arm', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 647],
            ['code' => 'CONTROL_ARM_BUSH', 'name' => 'Control Arm Bush', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 648],
        ]],
        ['code' => 'BALL_JOINT', 'name' => 'Ball Joint', 'subcategories' => [
            ['code' => 'UPPER_BALL_JOINT', 'name' => 'Upper Ball Joint', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 649],
            ['code' => 'LOWER_BALL_JOINT', 'name' => 'Lower Ball Joint', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 650],
        ]],
        ['code' => 'STABILIZER', 'name' => 'Stabilizer', 'subcategories' => [
            ['code' => 'STABILIZER_BAR', 'name' => 'Stabilizer Bar', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 651],
            ['code' => 'STABILIZER_LINK', 'name' => 'Stabilizer Link', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 652],
            ['code' => 'STABILIZER_BUSH', 'name' => 'Stabilizer Bush', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 653],
        ]],
        ['code' => 'TORQUE_ROD', 'name' => 'Torque Rod', 'subcategories' => [
            ['code' => 'TORQUE_ROD', 'name' => 'Torque Rod', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 654],
        ]],
        ['code' => 'RADIUS_ROD', 'name' => 'Radius Rod', 'subcategories' => [
            ['code' => 'RADIUS_ROD', 'name' => 'Radius Rod', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 655],
        ]],
        ['code' => 'PANHARD_ROD', 'name' => 'Panhard Rod', 'subcategories' => [
            ['code' => 'PANHARD_ROD', 'name' => 'Panhard Rod', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 656],
        ]],
        ['code' => 'TORSION_BAR', 'name' => 'Torsion Bar', 'subcategories' => [
            ['code' => 'TORSION_BAR', 'name' => 'Torsion Bar', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 657],
        ]],
        ['code' => 'SUSPENSION_BUSHING', 'name' => 'Suspension Bushing', 'subcategories' => [
            ['code' => 'RUBBER_BUSH', 'name' => 'Rubber Bush', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 658],
            ['code' => 'POLYURETHANE_BUSH', 'name' => 'Polyurethane Bush', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 659],
        ]],
        ['code' => 'BUMP_STOP', 'name' => 'Bump Stop', 'subcategories' => [
            ['code' => 'BUMP_RUBBER', 'name' => 'Bump Rubber', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 660],
        ]],
        ['code' => 'HEIGHT_SENSOR', 'name' => 'Height Sensor', 'subcategories' => [
            ['code' => 'RIDE_HEIGHT_SENSOR', 'name' => 'Ride Height Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 661],
        ]],
    ],
    'CG-HYD' => [
        ['code' => 'HYDRAULIC_PUMP', 'name' => 'Hydraulic Pump', 'subcategories' => [
            ['code' => 'GEAR_PUMP', 'name' => 'Gear Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 669],
            ['code' => 'PISTON_PUMP', 'name' => 'Piston Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 670],
            ['code' => 'VANE_PUMP', 'name' => 'Vane Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 671],
            ['code' => 'TANDEM_PUMP', 'name' => 'Tandem Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 672],
        ]],
        ['code' => 'HYDRAULIC_MOTOR', 'name' => 'Hydraulic Motor', 'subcategories' => [
            ['code' => 'TRAVEL_MOTOR', 'name' => 'Travel Motor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 673],
            ['code' => 'SWING_MOTOR', 'name' => 'Swing Motor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 674],
            ['code' => 'FAN_MOTOR', 'name' => 'Fan Motor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 675],
        ]],
        ['code' => 'HYDRAULIC_CYLINDER', 'name' => 'Hydraulic Cylinder', 'subcategories' => [
            ['code' => 'BOOM_CYLINDER', 'name' => 'Boom Cylinder', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 676],
            ['code' => 'ARM_CYLINDER', 'name' => 'Arm Cylinder', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 677],
            ['code' => 'BUCKET_CYLINDER', 'name' => 'Bucket Cylinder', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 678],
            ['code' => 'STEERING_CYLINDER', 'name' => 'Steering Cylinder', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 679],
            ['code' => 'LIFT_CYLINDER', 'name' => 'Lift Cylinder', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 680],
        ]],
        ['code' => 'CYLINDER_COMPONENT', 'name' => 'Cylinder Component', 'subcategories' => [
            ['code' => 'PISTON_ROD', 'name' => 'Piston Rod', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 681],
            ['code' => 'PISTON', 'name' => 'Piston', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 682],
            ['code' => 'GLAND', 'name' => 'Gland', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 683],
            ['code' => 'SEAL_KIT', 'name' => 'Seal Kit', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 684],
        ]],
        ['code' => 'CONTROL_VALVE', 'name' => 'Control Valve', 'subcategories' => [
            ['code' => 'MAIN_CONTROL_VALVE', 'name' => 'Main Control Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 685],
            ['code' => 'DIRECTIONAL_VALVE', 'name' => 'Directional Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 686],
            ['code' => 'RELIEF_VALVE', 'name' => 'Relief Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 687],
            ['code' => 'CHECK_VALVE', 'name' => 'Check Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 688],
            ['code' => 'FLOW_CONTROL_VALVE', 'name' => 'Flow Control Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 689],
        ]],
        ['code' => 'SOLENOID_VALVE', 'name' => 'Solenoid Valve', 'subcategories' => [
            ['code' => 'HYDRAULIC_SOLENOID', 'name' => 'Hydraulic Solenoid', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 690],
        ]],
        ['code' => 'PILOT_SYSTEM', 'name' => 'Pilot System', 'subcategories' => [
            ['code' => 'PILOT_VALVE', 'name' => 'Pilot Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 691],
            ['code' => 'PILOT_PUMP', 'name' => 'Pilot Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 692],
            ['code' => 'PILOT_HOSE', 'name' => 'Pilot Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 693],
        ]],
        ['code' => 'ACCUMULATOR', 'name' => 'Accumulator', 'subcategories' => [
            ['code' => 'HYDRAULIC_ACCUMULATOR', 'name' => 'Hydraulic Accumulator', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 694],
        ]],
        ['code' => 'HYDRAULIC_HOSE', 'name' => 'Hydraulic Hose', 'subcategories' => [
            ['code' => 'HIGH_PRESSURE_HOSE', 'name' => 'High Pressure Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 695],
            ['code' => 'RETURN_HOSE', 'name' => 'Return Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 696],
            ['code' => 'SUCTION_HOSE', 'name' => 'Suction Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 697],
        ]],
        ['code' => 'HYDRAULIC_PIPE', 'name' => 'Hydraulic Pipe', 'subcategories' => [
            ['code' => 'STEEL_PIPE', 'name' => 'Steel Pipe', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 698],
        ]],
        ['code' => 'HYDRAULIC_FITTING', 'name' => 'Hydraulic Fitting', 'subcategories' => [
            ['code' => 'ELBOW', 'name' => 'Elbow', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 699],
            ['code' => 'TEE', 'name' => 'Tee', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 700],
            ['code' => 'ADAPTER', 'name' => 'Adapter', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 701],
            ['code' => 'QUICK_COUPLER', 'name' => 'Quick Coupler', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 702],
        ]],
        ['code' => 'RESERVOIR', 'name' => 'Reservoir', 'subcategories' => [
            ['code' => 'HYDRAULIC_TANK', 'name' => 'Hydraulic Tank', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 703],
            ['code' => 'BREATHER', 'name' => 'Breather', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 704],
            ['code' => 'LEVEL_GAUGE', 'name' => 'Level Gauge', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 705],
        ]],
        ['code' => 'FILTER', 'name' => 'Filter', 'subcategories' => [
            ['code' => 'RETURN_FILTER', 'name' => 'Return Filter', 'description' => null, 'item_types' => ['SPARE_PART', 'CONSUMABLE'], 'basis' => 'derived_filter', 'line' => 706],
            ['code' => 'SUCTION_FILTER', 'name' => 'Suction Filter', 'description' => null, 'item_types' => ['SPARE_PART', 'CONSUMABLE'], 'basis' => 'derived_filter', 'line' => 707],
            ['code' => 'PRESSURE_FILTER', 'name' => 'Pressure Filter', 'description' => null, 'item_types' => ['SPARE_PART', 'CONSUMABLE'], 'basis' => 'derived_filter', 'line' => 708],
            ['code' => 'PILOT_FILTER', 'name' => 'Pilot Filter', 'description' => null, 'item_types' => ['SPARE_PART', 'CONSUMABLE'], 'basis' => 'derived_filter', 'line' => 709],
        ]],
        ['code' => 'COOLER', 'name' => 'Cooler', 'subcategories' => [
            ['code' => 'HYDRAULIC_OIL_COOLER', 'name' => 'Hydraulic Oil Cooler', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 710],
        ]],
        ['code' => 'SENSOR', 'name' => 'Sensor', 'subcategories' => [
            ['code' => 'HYDRAULIC_PRESSURE_SENSOR', 'name' => 'Hydraulic Pressure Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 711],
            ['code' => 'OIL_TEMPERATURE_SENSOR', 'name' => 'Oil Temperature Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 712],
        ]],
        ['code' => 'OIL', 'name' => 'Oil', 'subcategories' => [
            ['code' => 'HYDRAULIC_OIL', 'name' => 'Hydraulic Oil', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 713],
        ]],
        ['code' => 'CHEMICAL', 'name' => 'Chemical', 'subcategories' => [
            ['code' => 'HYDRAULIC_SYSTEM_CLEANER', 'name' => 'Hydraulic System Cleaner', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 714],
        ]],
    ],
    'CG-PNEU' => [
        ['code' => 'AIR_COMPRESSOR', 'name' => 'Air Compressor', 'subcategories' => [
            ['code' => 'COMPRESSOR_ASSEMBLY', 'name' => 'Compressor Assembly', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 722],
            ['code' => 'COMPRESSOR_HEAD', 'name' => 'Compressor Head', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 723],
            ['code' => 'COMPRESSOR_REPAIR_KIT', 'name' => 'Compressor Repair Kit', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 724],
        ]],
        ['code' => 'AIR_DRYER', 'name' => 'Air Dryer', 'subcategories' => [
            ['code' => 'AIR_DRYER', 'name' => 'Air Dryer', 'description' => null, 'item_types' => ['SPARE_PART', 'CONSUMABLE'], 'basis' => 'derived_filter', 'line' => 725],
            ['code' => 'DRYER_CARTRIDGE', 'name' => 'Dryer Cartridge', 'description' => null, 'item_types' => ['SPARE_PART', 'CONSUMABLE'], 'basis' => 'derived_filter', 'line' => 726],
            ['code' => 'PURGE_VALVE', 'name' => 'Purge Valve', 'description' => null, 'item_types' => ['SPARE_PART', 'CONSUMABLE'], 'basis' => 'derived_filter', 'line' => 727],
        ]],
        ['code' => 'AIR_RESERVOIR', 'name' => 'Air Reservoir', 'subcategories' => [
            ['code' => 'PRIMARY_TANK', 'name' => 'Primary Tank', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 728],
            ['code' => 'SECONDARY_TANK', 'name' => 'Secondary Tank', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 729],
            ['code' => 'WET_TANK', 'name' => 'Wet Tank', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 730],
            ['code' => 'DRAIN_VALVE', 'name' => 'Drain Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 731],
        ]],
        ['code' => 'PROTECTION_VALVE', 'name' => 'Protection Valve', 'subcategories' => [
            ['code' => 'FOUR_CIRCUIT_PROTECTION_VALVE', 'name' => 'Four-Circuit Protection Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 732],
        ]],
        ['code' => 'PRESSURE_VALVE', 'name' => 'Pressure Valve', 'subcategories' => [
            ['code' => 'PRESSURE_REGULATOR', 'name' => 'Pressure Regulator', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 733],
        ]],
        ['code' => 'BRAKE_VALVE', 'name' => 'Brake Valve', 'subcategories' => [
            ['code' => 'FOOT_BRAKE_VALVE', 'name' => 'Foot Brake Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 734],
            ['code' => 'RELAY_VALVE', 'name' => 'Relay Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 735],
            ['code' => 'QUICK_RELEASE_VALVE', 'name' => 'Quick Release Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 736],
            ['code' => 'LOAD_SENSING_VALVE', 'name' => 'Load Sensing Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 737],
        ]],
        ['code' => 'PARKING_VALVE', 'name' => 'Parking Valve', 'subcategories' => [
            ['code' => 'HAND_BRAKE_VALVE', 'name' => 'Hand Brake Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 738],
        ]],
        ['code' => 'AIR_HOSE', 'name' => 'Air Hose', 'subcategories' => [
            ['code' => 'NYLON_TUBE', 'name' => 'Nylon Tube', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 739],
            ['code' => 'RUBBER_HOSE', 'name' => 'Rubber Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 740],
        ]],
        ['code' => 'FITTING', 'name' => 'Fitting', 'subcategories' => [
            ['code' => 'STRAIGHT_CONNECTOR', 'name' => 'Straight Connector', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 741],
            ['code' => 'ELBOW', 'name' => 'Elbow', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 742],
            ['code' => 'TEE', 'name' => 'Tee', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 743],
            ['code' => 'PUSH_IN_CONNECTOR', 'name' => 'Push-In Connector', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 744],
        ]],
        ['code' => 'PNEUMATIC_CYLINDER', 'name' => 'Pneumatic Cylinder', 'subcategories' => [
            ['code' => 'AIR_CYLINDER', 'name' => 'Air Cylinder', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 745],
        ]],
        ['code' => 'ACTUATOR', 'name' => 'Actuator', 'subcategories' => [
            ['code' => 'PNEUMATIC_ACTUATOR', 'name' => 'Pneumatic Actuator', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 746],
        ]],
        ['code' => 'GAUGE', 'name' => 'Gauge', 'subcategories' => [
            ['code' => 'AIR_PRESSURE_GAUGE', 'name' => 'Air Pressure Gauge', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 747],
        ]],
        ['code' => 'SENSOR', 'name' => 'Sensor', 'subcategories' => [
            ['code' => 'AIR_PRESSURE_SENSOR', 'name' => 'Air Pressure Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 748],
        ]],
        ['code' => 'SWITCH', 'name' => 'Switch', 'subcategories' => [
            ['code' => 'LOW_PRESSURE_SWITCH', 'name' => 'Low Pressure Switch', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 749],
        ]],
        ['code' => 'SILENCER', 'name' => 'Silencer', 'subcategories' => [
            ['code' => 'PNEUMATIC_MUFFLER', 'name' => 'Pneumatic Muffler', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 750],
        ]],
        ['code' => 'LUBRICATOR', 'name' => 'Lubricator', 'subcategories' => [
            ['code' => 'AIR_LUBRICATOR', 'name' => 'Air Lubricator', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 751],
        ]],
        ['code' => 'FILTER', 'name' => 'Filter', 'subcategories' => [
            ['code' => 'AIR_LINE_FILTER', 'name' => 'Air Line Filter', 'description' => null, 'item_types' => ['SPARE_PART', 'CONSUMABLE'], 'basis' => 'derived_filter', 'line' => 752],
        ]],
        ['code' => 'REGULATOR', 'name' => 'Regulator', 'subcategories' => [
            ['code' => 'FRL_REGULATOR', 'name' => 'FRL Regulator', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 753],
        ]],
    ],
    'CG-SWING' => [
        ['code' => 'SWING_MOTOR', 'name' => 'Swing Motor', 'subcategories' => [
            ['code' => 'SWING_HYDRAULIC_MOTOR', 'name' => 'Swing Hydraulic Motor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 763],
            ['code' => 'MOTOR_SEAL_KIT', 'name' => 'Motor Seal Kit', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 764],
        ]],
        ['code' => 'SWING_REDUCTION', 'name' => 'Swing Reduction', 'subcategories' => [
            ['code' => 'SWING_GEARBOX', 'name' => 'Swing Gearbox', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 765],
            ['code' => 'PLANETARY_GEAR', 'name' => 'Planetary Gear', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 766],
            ['code' => 'SUN_GEAR', 'name' => 'Sun Gear', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 767],
            ['code' => 'CARRIER', 'name' => 'Carrier', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 768],
            ['code' => 'PINION', 'name' => 'Pinion', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 769],
        ]],
        ['code' => 'SWING_BEARING', 'name' => 'Swing Bearing', 'subcategories' => [
            ['code' => 'SLEWING_BEARING', 'name' => 'Slewing Bearing', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 770],
            ['code' => 'BEARING_BOLT', 'name' => 'Bearing Bolt', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 771],
        ]],
        ['code' => 'SWING_GEAR', 'name' => 'Swing Gear', 'subcategories' => [
            ['code' => 'RING_GEAR', 'name' => 'Ring Gear', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 772],
        ]],
        ['code' => 'SWING_BRAKE', 'name' => 'Swing Brake', 'subcategories' => [
            ['code' => 'BRAKE_DISC', 'name' => 'Brake Disc', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 773],
            ['code' => 'BRAKE_PISTON', 'name' => 'Brake Piston', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 774],
            ['code' => 'BRAKE_SPRING', 'name' => 'Brake Spring', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 775],
        ]],
        ['code' => 'SWING_CONTROL', 'name' => 'Swing Control', 'subcategories' => [
            ['code' => 'SWING_VALVE', 'name' => 'Swing Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 776],
            ['code' => 'SWING_SOLENOID', 'name' => 'Swing Solenoid', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 777],
        ]],
        ['code' => 'SWING_SENSOR', 'name' => 'Swing Sensor', 'subcategories' => [
            ['code' => 'SWING_POSITION_SENSOR', 'name' => 'Swing Position Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 778],
            ['code' => 'ROTATION_SENSOR', 'name' => 'Rotation Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 779],
        ]],
        ['code' => 'LUBRICATION', 'name' => 'Lubrication', 'subcategories' => [
            ['code' => 'SWING_GEAR_OIL', 'name' => 'Swing Gear Oil', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 780],
            ['code' => 'SWING_BEARING_GREASE', 'name' => 'Swing Bearing Grease', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 781],
        ]],
        ['code' => 'SEAL', 'name' => 'Seal', 'subcategories' => [
            ['code' => 'SWING_GEAR_SEAL', 'name' => 'Swing Gear Seal', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 782],
            ['code' => 'O_RING', 'name' => 'O-Ring', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 783],
        ]],
        ['code' => 'HOSE', 'name' => 'Hose', 'subcategories' => [
            ['code' => 'SWING_HYDRAULIC_HOSE', 'name' => 'Swing Hydraulic Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 784],
        ]],
    ],
    'CG-UC' => [
        ['code' => 'TRACK_CHAIN', 'name' => 'Track Chain', 'subcategories' => [
            ['code' => 'TRACK_LINK', 'name' => 'Track Link', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 794],
            ['code' => 'TRACK_LINK_ASSEMBLY', 'name' => 'Track Link Assembly', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 795],
            ['code' => 'TRACK_PIN', 'name' => 'Track Pin', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 796],
            ['code' => 'TRACK_BUSH', 'name' => 'Track Bush', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 797],
        ]],
        ['code' => 'TRACK_SHOE', 'name' => 'Track Shoe', 'subcategories' => [
            ['code' => 'STANDARD_SHOE', 'name' => 'Standard Shoe', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 798],
            ['code' => 'GROUSER_SHOE', 'name' => 'Grouser Shoe', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 799],
            ['code' => 'RUBBER_PAD', 'name' => 'Rubber Pad', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 800],
            ['code' => 'TRACK_BOLT', 'name' => 'Track Bolt', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 801],
            ['code' => 'TRACK_NUT', 'name' => 'Track Nut', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 802],
        ]],
        ['code' => 'BOTTOM_ROLLER', 'name' => 'Bottom Roller', 'subcategories' => [
            ['code' => 'TRACK_ROLLER', 'name' => 'Track Roller', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 803],
            ['code' => 'SINGLE_FLANGE_ROLLER', 'name' => 'Single Flange Roller', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 804],
            ['code' => 'DOUBLE_FLANGE_ROLLER', 'name' => 'Double Flange Roller', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 805],
        ]],
        ['code' => 'CARRIER_ROLLER', 'name' => 'Carrier Roller', 'subcategories' => [
            ['code' => 'UPPER_ROLLER', 'name' => 'Upper Roller', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 806],
        ]],
        ['code' => 'IDLER', 'name' => 'Idler', 'subcategories' => [
            ['code' => 'FRONT_IDLER', 'name' => 'Front Idler', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 807],
            ['code' => 'IDLER_SHAFT', 'name' => 'Idler Shaft', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 808],
            ['code' => 'IDLER_BEARING', 'name' => 'Idler Bearing', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 809],
        ]],
        ['code' => 'SPROCKET', 'name' => 'Sprocket', 'subcategories' => [
            ['code' => 'SPROCKET_SEGMENT', 'name' => 'Sprocket Segment', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 810],
            ['code' => 'COMPLETE_SPROCKET', 'name' => 'Complete Sprocket', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 811],
        ]],
        ['code' => 'TRACK_ADJUSTER', 'name' => 'Track Adjuster', 'subcategories' => [
            ['code' => 'ADJUSTER_CYLINDER', 'name' => 'Adjuster Cylinder', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 812],
            ['code' => 'GREASE_VALVE', 'name' => 'Grease Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 813],
        ]],
        ['code' => 'RECOIL', 'name' => 'Recoil', 'subcategories' => [
            ['code' => 'RECOIL_SPRING', 'name' => 'Recoil Spring', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 814],
            ['code' => 'RECOIL_ROD', 'name' => 'Recoil Rod', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 815],
        ]],
        ['code' => 'GUARD', 'name' => 'Guard', 'subcategories' => [
            ['code' => 'TRACK_GUARD', 'name' => 'Track Guard', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 816],
            ['code' => 'ROLLER_GUARD', 'name' => 'Roller Guard', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 817],
        ]],
        ['code' => 'FINAL_DRIVE', 'name' => 'Final Drive', 'subcategories' => [
            ['code' => 'FINAL_DRIVE_ASSEMBLY', 'name' => 'Final Drive Assembly', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 818],
            ['code' => 'TRAVEL_REDUCTION_GEAR', 'name' => 'Travel Reduction Gear', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 819],
            ['code' => 'TRAVEL_MOTOR', 'name' => 'Travel Motor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 820],
        ]],
        ['code' => 'SEAL', 'name' => 'Seal', 'subcategories' => [
            ['code' => 'FLOATING_SEAL', 'name' => 'Floating Seal', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 821],
            ['code' => 'DUO_CONE_SEAL', 'name' => 'Duo Cone Seal', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 822],
        ]],
        ['code' => 'LUBRICANT', 'name' => 'Lubricant', 'subcategories' => [
            ['code' => 'TRACK_GREASE', 'name' => 'Track Grease', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 823],
            ['code' => 'FINAL_DRIVE_OIL', 'name' => 'Final Drive Oil', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 824],
        ]],
    ],
    'CG-TYRE' => [
        ['code' => 'PASSENGER_TIRE', 'name' => 'Passenger Tire', 'subcategories' => [
            ['code' => 'SUMMER_TIRE', 'name' => 'Summer Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 836],
            ['code' => 'ALL_SEASON_TIRE', 'name' => 'All-Season Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 837],
            ['code' => 'PERFORMANCE_TIRE', 'name' => 'Performance Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 838],
            ['code' => 'TOURING_TIRE', 'name' => 'Touring Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 839],
        ]],
        ['code' => 'SUV_TIRE', 'name' => 'SUV Tire', 'subcategories' => [
            ['code' => 'HIGHWAY_TERRAIN', 'name' => 'Highway Terrain', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 840],
            ['code' => 'ALL_TERRAIN', 'name' => 'All Terrain', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 841],
            ['code' => 'MUD_TERRAIN', 'name' => 'Mud Terrain', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 842],
        ]],
        ['code' => 'LIGHT_TRUCK_TIRE', 'name' => 'Light Truck Tire', 'subcategories' => [
            ['code' => 'COMMERCIAL_VAN_TIRE', 'name' => 'Commercial Van Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 843],
        ]],
        ['code' => 'TRUCK_TIRE', 'name' => 'Truck Tire', 'subcategories' => [
            ['code' => 'STEER_TIRE', 'name' => 'Steer Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 844],
            ['code' => 'DRIVE_TIRE', 'name' => 'Drive Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 845],
            ['code' => 'TRAILER_TIRE', 'name' => 'Trailer Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 846],
            ['code' => 'ALL_POSITION_TIRE', 'name' => 'All Position Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 847],
        ]],
        ['code' => 'BUS_TIRE', 'name' => 'Bus Tire', 'subcategories' => [
            ['code' => 'CITY_BUS_TIRE', 'name' => 'City Bus Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 848],
            ['code' => 'COACH_TIRE', 'name' => 'Coach Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 849],
            ['code' => 'ALL_POSITION_TIRE', 'name' => 'All Position Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 850],
        ]],
        ['code' => 'OTR_TIRE', 'name' => 'OTR Tire', 'subcategories' => [
            ['code' => 'EARTHMOVER_TIRE', 'name' => 'Earthmover Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 851],
            ['code' => 'LOADER_TIRE', 'name' => 'Loader Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 852],
            ['code' => 'GRADER_TIRE', 'name' => 'Grader Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 853],
        ]],
        ['code' => 'INDUSTRIAL_TIRE', 'name' => 'Industrial Tire', 'subcategories' => [
            ['code' => 'PNEUMATIC_FORKLIFT_TIRE', 'name' => 'Pneumatic Forklift Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 854],
            ['code' => 'SOLID_TIRE', 'name' => 'Solid Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 855],
        ]],
        ['code' => 'CONSTRUCTION', 'name' => 'Construction', 'subcategories' => [
            ['code' => 'RADIAL_TIRE', 'name' => 'Radial Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 856],
            ['code' => 'BIAS_TIRE', 'name' => 'Bias Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 857],
            ['code' => 'SOLID_TIRE', 'name' => 'Solid Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 858],
        ]],
        ['code' => 'TUBE_SYSTEM', 'name' => 'Tube System', 'subcategories' => [
            ['code' => 'TUBELESS_TIRE', 'name' => 'Tubeless Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 859],
            ['code' => 'TUBE_TYPE_TIRE', 'name' => 'Tube Type Tire', 'description' => null, 'item_types' => ['TIRE'], 'basis' => 'explicit_section', 'line' => 860],
        ]],
        ['code' => 'INNER_COMPONENTS', 'name' => 'Inner Components', 'subcategories' => [
            ['code' => 'INNER_TUBE', 'name' => 'Inner Tube', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 861],
            ['code' => 'FLAP', 'name' => 'Flap', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 862],
        ]],
        ['code' => 'STEEL_RIM', 'name' => 'Steel Rim', 'subcategories' => [
            ['code' => 'PASSENGER_STEEL_RIM', 'name' => 'Passenger Steel Rim', 'description' => null, 'item_types' => ['RIM'], 'basis' => 'explicit_section', 'line' => 868],
            ['code' => 'LIGHT_TRUCK_STEEL_RIM', 'name' => 'Light Truck Steel Rim', 'description' => null, 'item_types' => ['RIM'], 'basis' => 'explicit_section', 'line' => 869],
            ['code' => 'TRUCK_BUS_STEEL_RIM', 'name' => 'Truck/Bus Steel Rim', 'description' => null, 'item_types' => ['RIM'], 'basis' => 'explicit_section', 'line' => 870],
        ]],
        ['code' => 'ALLOY_RIM', 'name' => 'Alloy Rim', 'subcategories' => [
            ['code' => 'CAST_ALLOY_RIM', 'name' => 'Cast Alloy Rim', 'description' => null, 'item_types' => ['RIM'], 'basis' => 'explicit_section', 'line' => 871],
            ['code' => 'FORGED_ALLOY_RIM', 'name' => 'Forged Alloy Rim', 'description' => null, 'item_types' => ['RIM'], 'basis' => 'explicit_section', 'line' => 872],
        ]],
        ['code' => 'HEAVY_DUTY_RIM', 'name' => 'Heavy Duty Rim', 'subcategories' => [
            ['code' => 'OTR_RIM', 'name' => 'OTR Rim', 'description' => null, 'item_types' => ['RIM'], 'basis' => 'explicit_section', 'line' => 873],
        ]],
        ['code' => 'MULTI_PIECE_RIM', 'name' => 'Multi-Piece Rim', 'subcategories' => [
            ['code' => 'SPLIT_RIM', 'name' => 'Split Rim', 'description' => null, 'item_types' => ['RIM'], 'basis' => 'explicit_section', 'line' => 874],
            ['code' => 'LOCK_RING', 'name' => 'Lock Ring', 'description' => null, 'item_types' => ['RIM'], 'basis' => 'explicit_section', 'line' => 875],
            ['code' => 'SIDE_RING', 'name' => 'Side Ring', 'description' => null, 'item_types' => ['RIM'], 'basis' => 'explicit_section', 'line' => 876],
        ]],
        ['code' => 'INDUSTRIAL_RIM', 'name' => 'Industrial Rim', 'subcategories' => [
            ['code' => 'FORKLIFT_RIM', 'name' => 'Forklift Rim', 'description' => null, 'item_types' => ['RIM'], 'basis' => 'explicit_section', 'line' => 877],
        ]],
        ['code' => 'WHEEL_FASTENER', 'name' => 'Wheel Fastener', 'subcategories' => [
            ['code' => 'WHEEL_NUT', 'name' => 'Wheel Nut', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'explicit_section', 'line' => 883],
            ['code' => 'WHEEL_BOLT', 'name' => 'Wheel Bolt', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'explicit_section', 'line' => 884],
            ['code' => 'WHEEL_STUD', 'name' => 'Wheel Stud', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'explicit_section', 'line' => 885],
        ]],
        ['code' => 'VALVE', 'name' => 'Valve', 'subcategories' => [
            ['code' => 'TUBELESS_VALVE', 'name' => 'Tubeless Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'explicit_section', 'line' => 886],
            ['code' => 'TRUCK_VALVE', 'name' => 'Truck Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'explicit_section', 'line' => 887],
            ['code' => 'VALVE_CORE', 'name' => 'Valve Core', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'explicit_section', 'line' => 888],
            ['code' => 'VALVE_CAP', 'name' => 'Valve Cap', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'explicit_section', 'line' => 889],
        ]],
        ['code' => 'TPMS', 'name' => 'TPMS', 'subcategories' => [
            ['code' => 'TPMS_SENSOR', 'name' => 'TPMS Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'explicit_section', 'line' => 890],
            ['code' => 'TPMS_VALVE', 'name' => 'TPMS Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'explicit_section', 'line' => 891],
        ]],
        ['code' => 'BALANCE', 'name' => 'Balance', 'subcategories' => [
            ['code' => 'WHEEL_WEIGHT', 'name' => 'Wheel Weight', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'explicit_section', 'line' => 892],
        ]],
        ['code' => 'CONSUMABLE', 'name' => 'Consumable', 'subcategories' => [
            ['code' => 'TIRE_PATCH', 'name' => 'Tire Patch', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'explicit_section', 'line' => 893],
            ['code' => 'VULCANIZING_CEMENT', 'name' => 'Vulcanizing Cement', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'explicit_section', 'line' => 894],
            ['code' => 'TIRE_SEALANT', 'name' => 'Tire Sealant', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'explicit_section', 'line' => 895],
            ['code' => 'BEAD_LUBRICANT', 'name' => 'Bead Lubricant', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'explicit_section', 'line' => 896],
        ]],
    ],
    'CG-ATTACH' => [
        ['code' => 'BUCKET', 'name' => 'Bucket', 'subcategories' => [
            ['code' => 'GENERAL_PURPOSE_BUCKET', 'name' => 'General Purpose Bucket', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 904],
            ['code' => 'HEAVY_DUTY_BUCKET', 'name' => 'Heavy Duty Bucket', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 905],
            ['code' => 'ROCK_BUCKET', 'name' => 'Rock Bucket', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 906],
            ['code' => 'TRENCHING_BUCKET', 'name' => 'Trenching Bucket', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 907],
            ['code' => 'DITCH_CLEANING_BUCKET', 'name' => 'Ditch Cleaning Bucket', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 908],
            ['code' => 'SKELETON_BUCKET', 'name' => 'Skeleton Bucket', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 909],
        ]],
        ['code' => 'BUCKET_COMPONENT', 'name' => 'Bucket Component', 'subcategories' => [
            ['code' => 'BUCKET_TOOTH', 'name' => 'Bucket Tooth', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 910],
            ['code' => 'TOOTH_ADAPTER', 'name' => 'Tooth Adapter', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 911],
            ['code' => 'SIDE_CUTTER', 'name' => 'Side Cutter', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 912],
            ['code' => 'CUTTING_EDGE', 'name' => 'Cutting Edge', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 913],
            ['code' => 'HEEL_SHROUD', 'name' => 'Heel Shroud', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 914],
            ['code' => 'WEAR_PLATE', 'name' => 'Wear Plate', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 915],
            ['code' => 'BUCKET_PIN', 'name' => 'Bucket Pin', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 916],
            ['code' => 'BUCKET_BUSH', 'name' => 'Bucket Bush', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 917],
        ]],
        ['code' => 'HYDRAULIC_BREAKER', 'name' => 'Hydraulic Breaker', 'subcategories' => [
            ['code' => 'BREAKER_ASSEMBLY', 'name' => 'Breaker Assembly', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 918],
            ['code' => 'CHISEL', 'name' => 'Chisel', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 919],
            ['code' => 'THROUGH_BOLT', 'name' => 'Through Bolt', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 920],
            ['code' => 'DIAPHRAGM', 'name' => 'Diaphragm', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 921],
            ['code' => 'SEAL_KIT', 'name' => 'Seal Kit', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 922],
        ]],
        ['code' => 'GRAPPLE', 'name' => 'Grapple', 'subcategories' => [
            ['code' => 'MECHANICAL_GRAPPLE', 'name' => 'Mechanical Grapple', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 923],
            ['code' => 'HYDRAULIC_GRAPPLE', 'name' => 'Hydraulic Grapple', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 924],
        ]],
        ['code' => 'FORK', 'name' => 'Fork', 'subcategories' => [
            ['code' => 'PALLET_FORK', 'name' => 'Pallet Fork', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 925],
            ['code' => 'FORK_TINE', 'name' => 'Fork Tine', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 926],
        ]],
        ['code' => 'BLADE', 'name' => 'Blade', 'subcategories' => [
            ['code' => 'DOZER_BLADE', 'name' => 'Dozer Blade', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 927],
            ['code' => 'CUTTING_EDGE', 'name' => 'Cutting Edge', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 928],
            ['code' => 'END_BIT', 'name' => 'End Bit', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 929],
        ]],
        ['code' => 'AUGER', 'name' => 'Auger', 'subcategories' => [
            ['code' => 'AUGER_DRIVE', 'name' => 'Auger Drive', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 930],
            ['code' => 'AUGER_BIT', 'name' => 'Auger Bit', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 931],
        ]],
        ['code' => 'COMPACTOR', 'name' => 'Compactor', 'subcategories' => [
            ['code' => 'PLATE_COMPACTOR', 'name' => 'Plate Compactor', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 932],
        ]],
        ['code' => 'COUPLER', 'name' => 'Coupler', 'subcategories' => [
            ['code' => 'QUICK_COUPLER', 'name' => 'Quick Coupler', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 933],
            ['code' => 'COUPLER_PIN', 'name' => 'Coupler Pin', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 934],
        ]],
        ['code' => 'CRANE_ATTACHMENT', 'name' => 'Crane Attachment', 'subcategories' => [
            ['code' => 'HOOK', 'name' => 'Hook', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 935],
            ['code' => 'SHEAVE', 'name' => 'Sheave', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 936],
            ['code' => 'WIRE_ROPE', 'name' => 'Wire Rope', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 937],
            ['code' => 'LOAD_BLOCK', 'name' => 'Load Block', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 938],
        ]],
        ['code' => 'HYDRAULIC_ATTACHMENT', 'name' => 'Hydraulic Attachment', 'subcategories' => [
            ['code' => 'ATTACHMENT_HOSE', 'name' => 'Attachment Hose', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 939],
            ['code' => 'QUICK_COUPLER', 'name' => 'Quick Coupler', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 940],
            ['code' => 'CONTROL_VALVE', 'name' => 'Control Valve', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 941],
        ]],
    ],
    'CG-ACC' => [
        ['code' => 'SAFETY', 'name' => 'Safety', 'subcategories' => [
            ['code' => 'FIRE_EXTINGUISHER', 'name' => 'Fire Extinguisher', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 949],
            ['code' => 'WARNING_TRIANGLE', 'name' => 'Warning Triangle', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 950],
            ['code' => 'SAFETY_HAMMER', 'name' => 'Safety Hammer', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 951],
            ['code' => 'FIRST_AID_BOX', 'name' => 'First Aid Box', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 952],
            ['code' => 'WHEEL_CHOCK', 'name' => 'Wheel Chock', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 953],
            ['code' => 'REFLECTIVE_VEST', 'name' => 'Reflective Vest', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 954],
        ]],
        ['code' => 'EXTERIOR', 'name' => 'Exterior', 'subcategories' => [
            ['code' => 'MUD_FLAP', 'name' => 'Mud Flap', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 955],
            ['code' => 'SIDE_STEP', 'name' => 'Side Step', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 956],
            ['code' => 'ROOF_RACK', 'name' => 'Roof Rack', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 957],
            ['code' => 'BULL_BAR', 'name' => 'Bull Bar', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 958],
            ['code' => 'WEATHER_SHIELD', 'name' => 'Weather Shield', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 959],
            ['code' => 'MIRROR_EXTENSION', 'name' => 'Mirror Extension', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 960],
        ]],
        ['code' => 'INTERIOR', 'name' => 'Interior', 'subcategories' => [
            ['code' => 'FLOOR_MAT', 'name' => 'Floor Mat', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 961],
            ['code' => 'SEAT_COVER', 'name' => 'Seat Cover', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 962],
            ['code' => 'SUN_VISOR', 'name' => 'Sun Visor', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 963],
            ['code' => 'STORAGE_ORGANIZER', 'name' => 'Storage Organizer', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 964],
        ]],
        ['code' => 'AUDIO', 'name' => 'Audio', 'subcategories' => [
            ['code' => 'HEAD_UNIT', 'name' => 'Head Unit', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 965],
            ['code' => 'SPEAKER', 'name' => 'Speaker', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 966],
            ['code' => 'AMPLIFIER', 'name' => 'Amplifier', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 967],
        ]],
        ['code' => 'NAVIGATION', 'name' => 'Navigation', 'subcategories' => [
            ['code' => 'NAVIGATION_UNIT', 'name' => 'Navigation Unit', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 968],
        ]],
        ['code' => 'TELEMATICS', 'name' => 'Telematics', 'subcategories' => [
            ['code' => 'GPS_TRACKER', 'name' => 'GPS Tracker', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 969],
            ['code' => 'MDT', 'name' => 'MDT', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 970],
            ['code' => 'CAN_ADAPTER', 'name' => 'CAN Adapter', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 971],
        ]],
        ['code' => 'CAMERA', 'name' => 'Camera', 'subcategories' => [
            ['code' => 'DASH_CAMERA', 'name' => 'Dash Camera', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 972],
            ['code' => 'REVERSE_CAMERA', 'name' => 'Reverse Camera', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 973],
            ['code' => 'MDVR', 'name' => 'MDVR', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 974],
            ['code' => 'SURROUND_CAMERA', 'name' => 'Surround Camera', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 975],
        ]],
        ['code' => 'PARKING_ASSIST', 'name' => 'Parking Assist', 'subcategories' => [
            ['code' => 'PARKING_SENSOR', 'name' => 'Parking Sensor', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 976],
        ]],
        ['code' => 'COMMUNICATION', 'name' => 'Communication', 'subcategories' => [
            ['code' => 'TWO_WAY_RADIO', 'name' => 'Two-Way Radio', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 977],
        ]],
        ['code' => 'LIGHTING', 'name' => 'Lighting', 'subcategories' => [
            ['code' => 'WORK_LAMP', 'name' => 'Work Lamp', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 978],
            ['code' => 'BEACON', 'name' => 'Beacon', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 979],
            ['code' => 'AUXILIARY_LAMP', 'name' => 'Auxiliary Lamp', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 980],
        ]],
        ['code' => 'ELECTRICAL_ACCESSORY', 'name' => 'Electrical Accessory', 'subcategories' => [
            ['code' => 'USB_CHARGER', 'name' => 'USB Charger', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 981],
            ['code' => 'POWER_INVERTER', 'name' => 'Power Inverter', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 982],
        ]],
        ['code' => 'SECURITY', 'name' => 'Security', 'subcategories' => [
            ['code' => 'ALARM', 'name' => 'Alarm', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 983],
            ['code' => 'IMMOBILIZER', 'name' => 'Immobilizer', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 984],
            ['code' => 'CENTRAL_LOCK_MODULE', 'name' => 'Central Lock Module', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 985],
        ]],
        ['code' => 'STORAGE', 'name' => 'Storage', 'subcategories' => [
            ['code' => 'TOOL_BOX', 'name' => 'Tool Box', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 986],
            ['code' => 'CARGO_BOX', 'name' => 'Cargo Box', 'description' => null, 'item_types' => [], 'basis' => 'unmapped', 'line' => 987],
        ]],
    ],
    'CG-HVAC' => [
        ['code' => 'COMPRESSOR', 'name' => 'Compressor', 'subcategories' => [
            ['code' => 'AC_COMPRESSOR', 'name' => 'AC Compressor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1001],
            ['code' => 'COMPRESSOR_CLUTCH', 'name' => 'Compressor Clutch', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1002],
            ['code' => 'PULLEY', 'name' => 'Pulley', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1003],
        ]],
        ['code' => 'CONDENSER', 'name' => 'Condenser', 'subcategories' => [
            ['code' => 'AC_CONDENSER', 'name' => 'AC Condenser', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1004],
        ]],
        ['code' => 'EVAPORATOR', 'name' => 'Evaporator', 'subcategories' => [
            ['code' => 'EVAPORATOR_CORE', 'name' => 'Evaporator Core', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1005],
        ]],
        ['code' => 'EXPANSION', 'name' => 'Expansion', 'subcategories' => [
            ['code' => 'EXPANSION_VALVE', 'name' => 'Expansion Valve', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1006],
            ['code' => 'ORIFICE_TUBE', 'name' => 'Orifice Tube', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1007],
        ]],
        ['code' => 'RECEIVER', 'name' => 'Receiver', 'subcategories' => [
            ['code' => 'RECEIVER_DRYER', 'name' => 'Receiver Dryer', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1008],
        ]],
        ['code' => 'BLOWER', 'name' => 'Blower', 'subcategories' => [
            ['code' => 'BLOWER_MOTOR', 'name' => 'Blower Motor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1009],
            ['code' => 'BLOWER_RESISTOR', 'name' => 'Blower Resistor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1010],
        ]],
        ['code' => 'HEATER', 'name' => 'Heater', 'subcategories' => [
            ['code' => 'HEATER_CORE', 'name' => 'Heater Core', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1011],
        ]],
        ['code' => 'AIR_DISTRIBUTION', 'name' => 'Air Distribution', 'subcategories' => [
            ['code' => 'AIR_DUCT', 'name' => 'Air Duct', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1012],
            ['code' => 'VENT', 'name' => 'Vent', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1013],
        ]],
        ['code' => 'HVAC_CONTROL', 'name' => 'HVAC Control', 'subcategories' => [
            ['code' => 'AC_CONTROL_PANEL', 'name' => 'AC Control Panel', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1014],
            ['code' => 'HVAC_ECU', 'name' => 'HVAC ECU', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1015],
        ]],
        ['code' => 'SENSOR', 'name' => 'Sensor', 'subcategories' => [
            ['code' => 'CABIN_TEMPERATURE_SENSOR', 'name' => 'Cabin Temperature Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1016],
            ['code' => 'EVAPORATOR_SENSOR', 'name' => 'Evaporator Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1017],
            ['code' => 'PRESSURE_SENSOR', 'name' => 'Pressure Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1018],
        ]],
        ['code' => 'HOSE', 'name' => 'Hose', 'subcategories' => [
            ['code' => 'REFRIGERANT_HOSE', 'name' => 'Refrigerant Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1019],
        ]],
        ['code' => 'SEAL', 'name' => 'Seal', 'subcategories' => [
            ['code' => 'AC_O_RING', 'name' => 'AC O-Ring', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1020],
        ]],
        ['code' => 'REFRIGERANT', 'name' => 'Refrigerant', 'subcategories' => [
            ['code' => 'R134A_R1234YF', 'name' => 'R134a / R1234yf', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 1021],
        ]],
        ['code' => 'LUBRICANT', 'name' => 'Lubricant', 'subcategories' => [
            ['code' => 'COMPRESSOR_OIL', 'name' => 'Compressor Oil', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 1022],
        ]],
        ['code' => 'CONSUMABLE', 'name' => 'Consumable', 'subcategories' => [
            ['code' => 'AC_CLEANER', 'name' => 'AC Cleaner', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 1023],
        ]],
    ],
    'CG-BODY' => [
        ['code' => 'BODY_PANEL', 'name' => 'Body Panel', 'subcategories' => [
            ['code' => 'HOOD', 'name' => 'Hood', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1031],
            ['code' => 'FENDER', 'name' => 'Fender', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1032],
            ['code' => 'DOOR_PANEL', 'name' => 'Door Panel', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1033],
            ['code' => 'QUARTER_PANEL', 'name' => 'Quarter Panel', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1034],
            ['code' => 'ROOF_PANEL', 'name' => 'Roof Panel', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1035],
            ['code' => 'BUMPER', 'name' => 'Bumper', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1036],
        ]],
        ['code' => 'DOOR', 'name' => 'Door', 'subcategories' => [
            ['code' => 'DOOR_ASSEMBLY', 'name' => 'Door Assembly', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1037],
            ['code' => 'DOOR_HINGE', 'name' => 'Door Hinge', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1038],
            ['code' => 'DOOR_HANDLE', 'name' => 'Door Handle', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1039],
            ['code' => 'DOOR_LOCK', 'name' => 'Door Lock', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1040],
            ['code' => 'DOOR_SEAL', 'name' => 'Door Seal', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1041],
        ]],
        ['code' => 'WINDOW', 'name' => 'Window', 'subcategories' => [
            ['code' => 'WINDOW_REGULATOR', 'name' => 'Window Regulator', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1042],
            ['code' => 'WINDOW_MOTOR', 'name' => 'Window Motor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1043],
        ]],
        ['code' => 'MIRROR', 'name' => 'Mirror', 'subcategories' => [
            ['code' => 'SIDE_MIRROR', 'name' => 'Side Mirror', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1044],
            ['code' => 'MIRROR_MOTOR', 'name' => 'Mirror Motor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1045],
        ]],
        ['code' => 'SEAT', 'name' => 'Seat', 'subcategories' => [
            ['code' => 'DRIVER_SEAT', 'name' => 'Driver Seat', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1046],
            ['code' => 'PASSENGER_SEAT', 'name' => 'Passenger Seat', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1047],
            ['code' => 'SEAT_RAIL', 'name' => 'Seat Rail', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1048],
            ['code' => 'SEAT_MECHANISM', 'name' => 'Seat Mechanism', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1049],
        ]],
        ['code' => 'CABIN_MOUNT', 'name' => 'Cabin Mount', 'subcategories' => [
            ['code' => 'CAB_MOUNT', 'name' => 'Cab Mount', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1050],
            ['code' => 'CAB_MOUNT_BUSH', 'name' => 'Cab Mount Bush', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1051],
        ]],
        ['code' => 'HOOD', 'name' => 'Hood', 'subcategories' => [
            ['code' => 'HOOD_HINGE', 'name' => 'Hood Hinge', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1052],
            ['code' => 'HOOD_LOCK', 'name' => 'Hood Lock', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1053],
        ]],
        ['code' => 'CONSUMABLE', 'name' => 'Consumable', 'subcategories' => [
            ['code' => 'BODY_SEALANT', 'name' => 'Body Sealant', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 1054],
            ['code' => 'PAINT', 'name' => 'Paint', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 1055],
            ['code' => 'ADHESIVE', 'name' => 'Adhesive', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 1056],
        ]],
    ],
    'CG-GLASS' => [
        ['code' => 'GLASS', 'name' => 'Glass', 'subcategories' => [
            ['code' => 'WINDSHIELD', 'name' => 'Windshield', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1064],
            ['code' => 'REAR_GLASS', 'name' => 'Rear Glass', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1065],
            ['code' => 'DOOR_GLASS', 'name' => 'Door Glass', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1066],
            ['code' => 'QUARTER_GLASS', 'name' => 'Quarter Glass', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1067],
        ]],
        ['code' => 'GLASS_HARDWARE', 'name' => 'Glass Hardware', 'subcategories' => [
            ['code' => 'GLASS_MOLDING', 'name' => 'Glass Molding', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1068],
            ['code' => 'WEATHER_STRIP', 'name' => 'Weather Strip', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1069],
        ]],
        ['code' => 'WIPER', 'name' => 'Wiper', 'subcategories' => [
            ['code' => 'WIPER_BLADE', 'name' => 'Wiper Blade', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1070],
            ['code' => 'WIPER_ARM', 'name' => 'Wiper Arm', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1071],
            ['code' => 'WIPER_MOTOR', 'name' => 'Wiper Motor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1072],
            ['code' => 'WIPER_LINKAGE', 'name' => 'Wiper Linkage', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1073],
        ]],
        ['code' => 'WASHER', 'name' => 'Washer', 'subcategories' => [
            ['code' => 'WASHER_PUMP', 'name' => 'Washer Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1074],
            ['code' => 'WASHER_TANK', 'name' => 'Washer Tank', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1075],
            ['code' => 'WASHER_HOSE', 'name' => 'Washer Hose', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1076],
            ['code' => 'WASHER_NOZZLE', 'name' => 'Washer Nozzle', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1077],
        ]],
        ['code' => 'CONSUMABLE', 'name' => 'Consumable', 'subcategories' => [
            ['code' => 'WASHER_FLUID', 'name' => 'Washer Fluid', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 1078],
            ['code' => 'GLASS_CLEANER', 'name' => 'Glass Cleaner', 'description' => null, 'item_types' => ['CONSUMABLE'], 'basis' => 'derived_material', 'line' => 1079],
        ]],
    ],
    'CG-SRS' => [
        ['code' => 'SEAT_BELT', 'name' => 'Seat Belt', 'subcategories' => [
            ['code' => 'SEAT_BELT_ASSEMBLY', 'name' => 'Seat Belt Assembly', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1087],
            ['code' => 'PRETENSIONER', 'name' => 'Pretensioner', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1088],
        ]],
        ['code' => 'AIRBAG', 'name' => 'Airbag', 'subcategories' => [
            ['code' => 'DRIVER_AIRBAG', 'name' => 'Driver Airbag', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1089],
            ['code' => 'PASSENGER_AIRBAG', 'name' => 'Passenger Airbag', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1090],
            ['code' => 'SIDE_AIRBAG', 'name' => 'Side Airbag', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1091],
            ['code' => 'CURTAIN_AIRBAG', 'name' => 'Curtain Airbag', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1092],
        ]],
        ['code' => 'AIRBAG_CONTROL', 'name' => 'Airbag Control', 'subcategories' => [
            ['code' => 'AIRBAG_ECU', 'name' => 'Airbag ECU', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1093],
        ]],
        ['code' => 'AIRBAG_SENSOR', 'name' => 'Airbag Sensor', 'subcategories' => [
            ['code' => 'CRASH_SENSOR', 'name' => 'Crash Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1094],
        ]],
        ['code' => 'OCCUPANCY', 'name' => 'Occupancy', 'subcategories' => [
            ['code' => 'SEAT_OCCUPANCY_SENSOR', 'name' => 'Seat Occupancy Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1095],
        ]],
        ['code' => 'CHILD_SAFETY', 'name' => 'Child Safety', 'subcategories' => [
            ['code' => 'ISOFIX_BRACKET', 'name' => 'ISOFIX Bracket', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1096],
        ]],
        ['code' => 'WARNING', 'name' => 'Warning', 'subcategories' => [
            ['code' => 'SEAT_BELT_SWITCH', 'name' => 'Seat Belt Switch', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1097],
        ]],
    ],
    'CG-EV-HV' => [
        ['code' => 'TRACTION_BATTERY', 'name' => 'Traction Battery', 'subcategories' => [
            ['code' => 'HV_BATTERY_PACK', 'name' => 'HV Battery Pack', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1107],
        ]],
        ['code' => 'BATTERY_MODULE', 'name' => 'Battery Module', 'subcategories' => [
            ['code' => 'BATTERY_MODULE', 'name' => 'Battery Module', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1108],
        ]],
        ['code' => 'BATTERY_CELL', 'name' => 'Battery Cell', 'subcategories' => [
            ['code' => 'CELL', 'name' => 'Cell', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1109],
        ]],
        ['code' => 'BATTERY_MANAGEMENT', 'name' => 'Battery Management', 'subcategories' => [
            ['code' => 'BMS', 'name' => 'BMS', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1110],
        ]],
        ['code' => 'HV_JUNCTION', 'name' => 'HV Junction', 'subcategories' => [
            ['code' => 'HV_JUNCTION_BOX', 'name' => 'HV Junction Box', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1111],
        ]],
        ['code' => 'CONTACTOR', 'name' => 'Contactor', 'subcategories' => [
            ['code' => 'MAIN_CONTACTOR', 'name' => 'Main Contactor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1112],
        ]],
        ['code' => 'FUSE', 'name' => 'Fuse', 'subcategories' => [
            ['code' => 'HV_FUSE', 'name' => 'HV Fuse', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1113],
        ]],
        ['code' => 'SERVICE_DISCONNECT', 'name' => 'Service Disconnect', 'subcategories' => [
            ['code' => 'MSD', 'name' => 'MSD', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1114],
        ]],
        ['code' => 'HV_CABLE', 'name' => 'HV Cable', 'subcategories' => [
            ['code' => 'ORANGE_HV_CABLE', 'name' => 'Orange HV Cable', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1115],
        ]],
        ['code' => 'HV_CONNECTOR', 'name' => 'HV Connector', 'subcategories' => [
            ['code' => 'HIGH_VOLTAGE_CONNECTOR', 'name' => 'High Voltage Connector', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1116],
        ]],
        ['code' => 'CHARGING', 'name' => 'Charging', 'subcategories' => [
            ['code' => 'ONBOARD_CHARGER', 'name' => 'Onboard Charger', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1117],
            ['code' => 'CHARGING_PORT', 'name' => 'Charging Port', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1118],
            ['code' => 'CHARGING_CABLE', 'name' => 'Charging Cable', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1119],
        ]],
        ['code' => 'DC_DC', 'name' => 'DC/DC', 'subcategories' => [
            ['code' => 'DC_DC_CONVERTER', 'name' => 'DC/DC Converter', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1120],
        ]],
        ['code' => 'INVERTER', 'name' => 'Inverter', 'subcategories' => [
            ['code' => 'TRACTION_INVERTER', 'name' => 'Traction Inverter', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1121],
        ]],
        ['code' => 'ELECTRIC_MOTOR', 'name' => 'Electric Motor', 'subcategories' => [
            ['code' => 'TRACTION_MOTOR', 'name' => 'Traction Motor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1122],
        ]],
        ['code' => 'REDUCTION_GEAR', 'name' => 'Reduction Gear', 'subcategories' => [
            ['code' => 'EV_REDUCTION_GEAR', 'name' => 'EV Reduction Gear', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1123],
        ]],
        ['code' => 'ELECTRIC_AC', 'name' => 'Electric AC', 'subcategories' => [
            ['code' => 'ELECTRIC_COMPRESSOR', 'name' => 'Electric Compressor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1124],
        ]],
        ['code' => 'HV_HEATER', 'name' => 'HV Heater', 'subcategories' => [
            ['code' => 'PTC_HEATER', 'name' => 'PTC Heater', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1125],
        ]],
        ['code' => 'BATTERY_COOLING', 'name' => 'Battery Cooling', 'subcategories' => [
            ['code' => 'COOLING_PLATE', 'name' => 'Cooling Plate', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1126],
            ['code' => 'COOLANT_PUMP', 'name' => 'Coolant Pump', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1127],
            ['code' => 'BATTERY_CHILLER', 'name' => 'Battery Chiller', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1128],
        ]],
        ['code' => 'SENSOR', 'name' => 'Sensor', 'subcategories' => [
            ['code' => 'BATTERY_TEMPERATURE_SENSOR', 'name' => 'Battery Temperature Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1129],
            ['code' => 'ISOLATION_SENSOR', 'name' => 'Isolation Sensor', 'description' => null, 'item_types' => ['SPARE_PART'], 'basis' => 'derived_component', 'line' => 1130],
        ]],
    ],
];
