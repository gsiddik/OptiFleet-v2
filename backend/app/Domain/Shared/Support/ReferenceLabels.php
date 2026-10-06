<?php

namespace App\Domain\Shared\Support;

/**
 * i18n structural preparation (S8, database localization): platform-seeded reference values are stored
 * once, by canonical code, with their English name. Their display text in another language comes from a
 * translation resource keyed by the EN-ID dataset key below — never from duplicated *_en / *_id columns
 * or a generic translation table.
 *
 * A key applies only while the stored name is still the seeded English default. A value an administrator
 * renamed is their own text and is shown exactly as stored.
 *
 * Generated from the seeded system rows (ModuleSeeder, MasterDataSeeder, ComponentGroupBaseline,
 * ProductReferenceDataSeeder); ReferenceLabelsTest keeps it in step with the seeders and the dataset.
 */
final class ReferenceLabels
{
    /** @var array<string, array<string, array{0: string, 1: string}>> table => code => [dataset key, seeded English name] */
    public const SYSTEM_VALUES = [
        'modules' => [
            'ACCESS_MANAGEMENT' => ['accessControl.labels.accessManagement', 'Access Management'],
            'ANALYTICS' => ['masterData.module.analyticsAndDataWarehouse', 'Analytics & Data Warehouse'],
            'COMPONENT' => ['masterData.module.componentTracking', 'Component Tracking'],
            'CONFIGURATION' => ['accessControl.labels.configuration', 'Configuration'],
            'CORE' => ['masterData.module.coreFoundation', 'Core Foundation'],
            'HISTORY' => ['masterData.module.historyAndRecords', 'History & Records'],
            'INSPECTION' => ['nav.groups.inspection', 'Inspection'],
            'INVENTORY' => ['masterData.module.inventory', 'Inventory'],
            'MAINTENANCE' => ['masterData.module.maintenancePlanning', 'Maintenance Planning'],
            'MAINTENANCE_INTELLIGENCE' => ['accessControl.labels.maintenanceIntelligence', 'Maintenance Intelligence'],
            'ORGANIZATION' => ['masterData.module.organizationManagement', 'Organization Management'],
            'PARTNER' => ['masterData.module.partnerVendor', 'Partner / Vendor'],
            'PROCUREMENT' => ['masterData.module.procurement', 'Procurement'],
            'REPORT' => ['masterData.module.reporting', 'Reporting'],
            'TELEMATICS' => ['masterData.module.telematics', 'Telematics'],
            'TIRE' => ['masterData.module.tireManagement', 'Tire Management'],
            'VEHICLE' => ['masterData.module.vehicleRegistry', 'Vehicle Registry'],
            'WARRANTY' => ['masterData.module.warranty', 'Warranty'],
            'WORKSHOP' => ['masterData.module.workshopOperations', 'Workshop Operations'],
            'WORK_ORDER' => ['common.labels.workOrder', 'Work Order'],
        ],
        'vehicle_categories' => [
            'VC-BUS' => ['masterData.masterData.bus', 'Bus'],
            'VC-EXC' => ['masterData.masterData.excavator', 'Excavator'],
            'VC-FORK' => ['masterData.masterData.forklift', 'Forklift'],
            'VC-HEQ' => ['masterData.masterData.heavyEquipment', 'Heavy Equipment'],
            'VC-PCAR' => ['masterData.masterData.passengerCar', 'Passenger Car'],
            'VC-TRUCK' => ['masterData.masterData.truck', 'Truck'],
            'VC-VAN' => ['masterData.masterData.van', 'Van'],
        ],
        'component_groups' => [
            'CG-ACC' => ['masterData.masterData.optionalAccessories', 'Optional Accessories'],
            'CG-ATTACH' => ['masterData.masterData.attachmentAndWorkEquipment', 'Attachment & Work Equipment'],
            'CG-AXLE' => ['masterData.masterData.travelDriveAxleAssembly', 'Travel Drive & Axle Assembly'],
            'CG-BODY' => ['masterData.masterData.bodyAndCabin', 'Body & Cabin'],
            'CG-BRAKE' => ['masterData.masterData.brakeSystem', 'Brake System'],
            'CG-CLUTCH' => ['masterData.masterData.clutchAndTorqueConverter', 'Clutch & Torque Converter'],
            'CG-ELEC' => ['masterData.masterData.electricalAndElectronicSystem', 'Electrical & Electronic System'],
            'CG-ENGINE' => ['masterData.masterData.engine', 'Engine'],
            'CG-ENGINE-COOL' => ['masterData.masterData.coolingSystem', 'Cooling System'],
            'CG-ENGINE-EXHAUST' => ['masterData.masterData.exhaustSystem', 'Exhaust System'],
            'CG-ENGINE-FUEL' => ['masterData.masterData.fuelSystem', 'Fuel System'],
            'CG-ENGINE-LUBE' => ['masterData.masterData.lubricationSystem', 'Lubrication System'],
            'CG-EV-HV' => ['masterData.masterData.evHighVoltageSystem', 'EV High Voltage System'],
            'CG-FRAME' => ['masterData.masterData.mainFrameGuardBogie', 'Main Frame, Guard & Bogie'],
            'CG-GLASS' => ['masterData.masterData.glassAndWasherSystem', 'Glass & Washer System'],
            'CG-HVAC' => ['masterData.masterData.hvacAirConditioningSystem', 'HVAC / Air Conditioning System'],
            'CG-HYD' => ['masterData.masterData.hydraulicSystem', 'Hydraulic System'],
            'CG-PNEU' => ['masterData.masterData.pneumaticSystem', 'Pneumatic System'],
            'CG-SRS' => ['masterData.masterData.safetyAndRestraintSystem', 'Safety & Restraint System'],
            'CG-STEER' => ['masterData.masterData.steeringSystem', 'Steering System'],
            'CG-SUSP' => ['masterData.masterData.suspensionSystem', 'Suspension System'],
            'CG-SWING' => ['masterData.masterData.swingSystem', 'Swing System'],
            'CG-TRANS' => ['masterData.masterData.transmissionSystem', 'Transmission System'],
            'CG-TYRE' => ['masterData.masterData.wheelAndTyreSystem', 'Wheel & Tyre System'],
            'CG-UC' => ['masterData.masterData.undercarriage', 'Undercarriage'],
        ],
        'product_categories' => [
            'PC-CONSUMABLE' => ['masterData.productReferenceData.consumables', 'Consumables'],
            'PC-EQUIPMENT' => ['masterData.productReferenceData.equipment', 'Equipment'],
            'PC-RIM' => ['masterData.productReferenceData.rims', 'Rims'],
            'PC-SPAREPART' => ['masterData.productReferenceData.spareParts', 'Spare Parts'],
            'PC-TIRE' => ['masterData.productReferenceData.tires', 'Tires'],
            'PC-TOOL' => ['masterData.productReferenceData.tools', 'Tools'],
        ],
        'uoms' => [
            'KG' => ['masterData.productReferenceData.kilogram', 'Kilogram'],
            'LTR' => ['masterData.productReferenceData.liter', 'Liter'],
            'PAIR' => ['masterData.productReferenceData.pair', 'Pair'],
            'PCS' => ['masterData.productReferenceData.piece', 'Piece'],
            'SET' => ['masterData.productReferenceData.set', 'Set'],
        ],
        'tool_types' => [
            'DIAGNOSTIC_TOOL' => ['masterData.productReferenceData.diagnosticTool', 'Diagnostic Tool'],
            'HAND_TOOL' => ['masterData.productReferenceData.handTool', 'Hand Tool'],
            'LIFTING_TOOL' => ['masterData.productReferenceData.liftingTool', 'Lifting Tool'],
            'MEASURING_TOOL' => ['masterData.productReferenceData.measuringTool', 'Measuring Tool'],
            'POWER_TOOL' => ['masterData.productReferenceData.powerTool', 'Power Tool'],
            'SPECIAL_SERVICE_TOOL' => ['masterData.productReferenceData.specialServiceTool', 'Special Service Tool'],
        ],
        'equipment_types' => [
            'CLEANING' => ['masterData.productReferenceData.cleaning', 'Cleaning'],
            'COMPRESSOR' => ['masterData.productReferenceData.compressor', 'Compressor'],
            'DIAGNOSTIC' => ['masterData.productReferenceData.diagnostic', 'Diagnostic'],
            'LIFTING' => ['masterData.productReferenceData.lifting', 'Lifting'],
            'LUBRICATION' => ['masterData.productReferenceData.lubrication', 'Lubrication'],
            'TIRE_SERVICE' => ['masterData.productReferenceData.tireService', 'Tire Service'],
            'WELDING' => ['masterData.productReferenceData.welding', 'Welding'],
            'WHEEL_ALIGNMENT' => ['masterData.productReferenceData.wheelAlignment', 'Wheel Alignment'],
            'WORKSHOP_MACHINERY' => ['masterData.productReferenceData.workshopMachinery', 'Workshop Machinery'],
        ],
        'storage_requirements' => [
            'AWAY_FROM_FOOD' => ['masterData.productReferenceData.awayFromFood', 'Away From Food'],
            'COOL_STORAGE' => ['masterData.productReferenceData.coolStorage', 'Cool Storage'],
            'DRY_STORAGE' => ['masterData.productReferenceData.dryStorage', 'Dry Storage'],
            'FLAMMABLE_STORAGE' => ['masterData.productReferenceData.flammableStorage', 'Flammable Storage'],
            'VENTILATED_AREA' => ['masterData.productReferenceData.ventilatedArea', 'Ventilated Area'],
        ],
    ];

    /** The translation key of a seeded system value while it keeps its seeded name; null otherwise. */
    public static function key(string $table, string $code, ?string $storedName = null): ?string
    {
        $entry = self::SYSTEM_VALUES[$table][$code] ?? null;
        if ($entry === null || ($storedName !== null && $storedName !== $entry[1])) {
            return null;
        }

        return $entry[0];
    }
}
