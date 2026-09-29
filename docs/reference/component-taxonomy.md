Benar. Jika master ini akan dipakai sungguhan untuk **warehouse bengkel, maintenance, procurement, work order, inspection, dan spare-part compatibility**, maka struktur Component Group perlu dibuat dari dua sudut sekaligus:

- **Warehouse:** barang harus mudah dicari, disimpan, dibeli, dihitung, dan dikendalikan stoknya.
- **Mechanic:** klasifikasi harus mengikuti assembly/subassembly yang nyata pada kendaraan sehingga teknisi tidak kesulitan menentukan sparepart.

Saya akan gunakan struktur:

**Component Group → Category / Assembly → Subcategory / Component Family → Contoh Item → Item Type**

Di bawah ini saya buat jauh lebih granular daripada daftar sebelumnya.

---

# A. Engine

| Category / Assembly | Subcategory / Component Family | Contoh Item | Item Type |
|---|---|---|---|
| Cylinder Block | Cylinder Block | Engine block | Sparepart |
| Cylinder Block | Cylinder Liner | Wet liner, dry liner | Sparepart |
| Cylinder Block | Core Plug | Freeze plug, expansion plug | Sparepart |
| Cylinder Block | Main Bearing Cap | Main cap | Sparepart |
| Cylinder Block | Engine Block Plug | Oil gallery plug | Sparepart |
| Cylinder Head | Cylinder Head | Bare head, complete head | Sparepart |
| Cylinder Head | Head Gasket | MLS gasket | Sparepart |
| Cylinder Head | Head Bolt | Cylinder head bolt | Sparepart |
| Cylinder Head | Valve Cover | Rocker cover | Sparepart |
| Cylinder Head | Valve Cover Gasket | Cover gasket | Sparepart |
| Piston Assembly | Piston | Standard/oversize piston | Sparepart |
| Piston Assembly | Piston Ring | Compression/oil ring | Sparepart |
| Piston Assembly | Piston Pin | Wrist pin | Sparepart |
| Piston Assembly | Piston Pin Bush | Small-end bush | Sparepart |
| Piston Assembly | Circlip | Piston pin circlip | Sparepart |
| Connecting Rod | Connecting Rod | Conrod | Sparepart |
| Connecting Rod | Big-End Bearing | Conrod bearing | Sparepart |
| Connecting Rod | Connecting Rod Bolt | Rod bolt/nut | Sparepart |
| Crankshaft | Crankshaft | Crankshaft assembly | Sparepart |
| Crankshaft | Main Bearing | Main journal bearing | Sparepart |
| Crankshaft | Thrust Bearing | Thrust washer | Sparepart |
| Crankshaft | Crankshaft Gear | Timing gear | Sparepart |
| Crankshaft | Front Oil Seal | Crank seal | Sparepart |
| Crankshaft | Rear Main Seal | Rear crank seal | Sparepart |
| Camshaft | Camshaft | Intake/exhaust camshaft | Sparepart |
| Camshaft | Cam Bearing | Cam bush/bearing | Sparepart |
| Camshaft | Cam Gear | Camshaft sprocket | Sparepart |
| Valve Train | Intake Valve | Intake valve | Sparepart |
| Valve Train | Exhaust Valve | Exhaust valve | Sparepart |
| Valve Train | Valve Guide | Guide | Sparepart |
| Valve Train | Valve Seat | Seat insert | Sparepart |
| Valve Train | Valve Spring | Inner/outer spring | Sparepart |
| Valve Train | Valve Keeper | Collet/lock | Sparepart |
| Valve Train | Valve Stem Seal | Stem seal | Sparepart |
| Valve Train | Rocker Arm | Rocker arm | Sparepart |
| Valve Train | Rocker Shaft | Rocker shaft | Sparepart |
| Valve Train | Push Rod | Push rod | Sparepart |
| Valve Train | Tappet / Lifter | Hydraulic/mechanical lifter | Sparepart |
| Valve Train | Lash Adjuster | Hydraulic lash adjuster | Sparepart |
| Timing System | Timing Belt | Timing belt | Sparepart |
| Timing System | Timing Chain | Timing chain | Sparepart |
| Timing System | Timing Gear | Gear train | Sparepart |
| Timing System | Tensioner | Hydraulic/manual tensioner | Sparepart |
| Timing System | Guide | Chain guide | Sparepart |
| Timing System | Idler Pulley | Timing idler | Sparepart |
| Timing System | Timing Cover | Front cover | Sparepart |
| Balance System | Balance Shaft | Balance shaft | Sparepart |
| Balance System | Balance Shaft Bearing | Bearing/bush | Sparepart |
| Flywheel | Flywheel | Flywheel | Sparepart |
| Flywheel | Ring Gear | Starter ring gear | Sparepart |
| Flexplate | Flexplate | Automatic transmission flexplate | Sparepart |
| Engine Mounting | Engine Mount | Rubber/hydraulic mount | Sparepart |
| Engine Mounting | Mounting Bracket | LH/RH bracket | Sparepart |
| Air Intake | Intake Manifold | Intake manifold | Sparepart |
| Air Intake | Air Intake Hose | Intake hose | Sparepart |
| Air Intake | Air Resonator | Resonator | Sparepart |
| Air Intake | Throttle Body | Electronic/mechanical throttle | Sparepart |
| Air Intake | Intake Gasket | Manifold gasket | Sparepart |
| Turbocharging | Turbocharger | Turbo assembly | Sparepart |
| Turbocharging | Turbo Cartridge | CHRA | Sparepart |
| Turbocharging | Wastegate | Actuator/wastegate | Sparepart |
| Turbocharging | VGT Actuator | Electronic/pneumatic actuator | Sparepart |
| Turbocharging | Turbo Oil Line | Feed/return pipe | Sparepart |
| Turbocharging | Turbo Coolant Line | Coolant hose/pipe | Sparepart |
| Charge Air | Intercooler | Charge air cooler | Sparepart |
| Charge Air | Intercooler Hose | Boost hose | Sparepart |
| Charge Air | Charge Pipe | Aluminum/plastic pipe | Sparepart |
| Crankcase Ventilation | PCV Valve | PCV valve | Sparepart |
| Crankcase Ventilation | Breather Hose | Hose | Sparepart |
| Engine Sensors | Crankshaft Sensor | CKP sensor | Sparepart |
| Engine Sensors | Camshaft Sensor | CMP sensor | Sparepart |
| Engine Sensors | Knock Sensor | Knock sensor | Sparepart |
| Engine Sensors | Manifold Pressure Sensor | MAP sensor | Sparepart |
| Engine Sensors | Air Flow Sensor | MAF sensor | Sparepart |
| Engine Sensors | Intake Air Temp Sensor | IAT sensor | Sparepart |
| Engine Service | Engine Cleaner | Carbon cleaner | Consumable |
| Engine Service | Gasket Maker | RTV sealant | Consumable |

---

# B. Lubrication System

| Category | Subcategory | Contoh |
|---|---|---|
| Engine Lubricant | Mineral Engine Oil | SAE 15W-40 |
| Engine Lubricant | Semi-Synthetic Engine Oil | SAE 10W-40 |
| Engine Lubricant | Fully Synthetic Engine Oil | SAE 5W-30 |
| Gear Lubricant | Manual Transmission Oil | 75W-90 |
| Gear Lubricant | Differential Oil | 80W-90 |
| Automatic Transmission | ATF | Dexron, ATF WS |
| CVT | CVT Fluid | CVTF |
| Hydraulic Lubricant | Hydraulic Oil | ISO VG 32/46/68 |
| Grease | Multipurpose Grease | Lithium grease |
| Grease | High Temperature Grease | Bearing grease |
| Grease | EP Grease | Extreme-pressure grease |
| Oil Pump | Engine Oil Pump | Rotor/gear pump |
| Oil Pump | Pump Housing | Oil pump housing |
| Oil Pump | Pressure Relief Valve | Relief valve |
| Oil Sump | Oil Pan | Sump |
| Oil Sump | Drain Plug | Drain bolt |
| Oil Sump | Drain Plug Washer | Copper/aluminum washer |
| Oil Pickup | Pickup Tube | Suction tube |
| Oil Pickup | Strainer | Pickup strainer |
| Oil Filter | Spin-On Filter | Engine filter |
| Oil Filter | Cartridge Filter | Cartridge element |
| Oil Filter | Filter Housing | Oil filter housing |
| Oil Cooler | Oil Cooler | Plate-type cooler |
| Oil Cooler | Cooler Gasket | Seal/gasket |
| Oil Cooler | Cooler Hose | Oil hose |
| Oil Line | Oil Pipe | Feed pipe |
| Oil Line | Flexible Oil Hose | Hose |
| Pressure Monitoring | Oil Pressure Sensor | Pressure sensor |
| Pressure Monitoring | Oil Pressure Switch | Warning switch |
| Level Monitoring | Oil Level Sensor | Level sensor |
| Dipstick | Oil Dipstick | Dipstick |
| Dipstick | Dipstick Tube | Tube |
| Lubrication Sealing | O-Ring | Various |
| Lubrication Sealing | Oil Seal | Various |
| Lubrication Sealing | Gasket | Various |

---

# C. Clutch System / Torque Converter

| Category | Subcategory | Contoh |
|---|---|---|
| Clutch Disc Assembly | Clutch Disc | Friction disc |
| Clutch Disc Assembly | Clutch Lining | Friction lining |
| Pressure Plate | Pressure Plate | Clutch cover |
| Pressure Plate | Diaphragm Spring | Spring |
| Release Mechanism | Release Bearing | Throw-out bearing |
| Release Mechanism | Release Fork | Clutch fork |
| Release Mechanism | Pivot Ball | Pivot |
| Release Mechanism | Release Sleeve | Guide sleeve |
| Pilot System | Pilot Bearing | Pilot bearing |
| Pilot System | Pilot Bush | Pilot bush |
| Clutch Hydraulics | Master Cylinder | Clutch master |
| Clutch Hydraulics | Slave Cylinder | Clutch slave |
| Clutch Hydraulics | Hydraulic Hose | Flexible hose |
| Clutch Hydraulics | Hydraulic Pipe | Hard line |
| Clutch Hydraulics | Repair Kit | Cup/seal kit |
| Clutch Mechanical | Clutch Cable | Cable |
| Clutch Mechanical | Pedal Linkage | Link/rod |
| Clutch Pedal | Pedal Assembly | Pedal |
| Clutch Pedal | Pedal Bush | Bushing |
| Flywheel | Flywheel | Single mass |
| Flywheel | Dual Mass Flywheel | DMF |
| Torque Converter | Torque Converter Assembly | Converter |
| Torque Converter | Lock-Up Clutch | Lock-up assembly |
| Torque Converter | Stator | Stator |
| Torque Converter | Turbine | Turbine |
| Torque Converter | Impeller | Pump/impeller |
| Torque Converter Control | Lock-Up Solenoid | Solenoid |
| Clutch Fluid | Brake/Clutch Fluid | DOT 3/4 |

---

# D. Cooling System

| Category | Subcategory |
|---|---|
| Radiator | Radiator Assembly |
| Radiator | Radiator Core |
| Radiator | Upper Tank |
| Radiator | Lower Tank |
| Radiator | Radiator Cap |
| Water Pump | Mechanical Water Pump |
| Water Pump | Electric Water Pump |
| Water Pump | Pump Impeller |
| Water Pump | Pump Gasket |
| Thermostat | Thermostat |
| Thermostat | Thermostat Housing |
| Fan | Mechanical Fan |
| Fan | Electric Fan |
| Fan | Fan Blade |
| Fan | Fan Motor |
| Fan | Viscous Fan Clutch |
| Fan Control | Fan Relay |
| Fan Control | Fan Controller |
| Hose | Upper Radiator Hose |
| Hose | Lower Radiator Hose |
| Hose | Bypass Hose |
| Hose | Heater Hose |
| Hose | Turbo Coolant Hose |
| Pipe | Coolant Pipe |
| Pipe | Water Outlet |
| Expansion | Expansion Tank |
| Expansion | Reservoir Cap |
| Sensor | Coolant Temperature Sensor |
| Sensor | Coolant Level Sensor |
| Sensor | Fan Switch |
| Heater Circuit | Heater Core |
| Heater Circuit | Heater Valve |
| Cooler | Transmission Oil Cooler |
| Cooler | Engine Oil Cooler |
| Clamp | Hose Clamp |
| Coolant | Ready-Mix Coolant |
| Coolant | Coolant Concentrate |
| Chemical | Radiator Flush |
| Chemical | Cooling System Sealer |

---

# E. Fuel System

| Category | Subcategory |
|---|---|
| Fuel Storage | Fuel Tank |
| Fuel Storage | Fuel Tank Cap |
| Fuel Storage | Tank Strap |
| Fuel Storage | Tank Sender |
| Fuel Delivery | Electric Fuel Pump |
| Fuel Delivery | Mechanical Fuel Pump |
| Fuel Delivery | Lift Pump |
| Fuel Delivery | High Pressure Pump |
| Fuel Delivery | Injection Pump |
| Fuel Filter | Primary Fuel Filter |
| Fuel Filter | Secondary Fuel Filter |
| Fuel Filter | Inline Fuel Filter |
| Water Separator | Water Separator |
| Water Separator | Separator Element |
| Injection | Fuel Injector |
| Injection | Injector Nozzle |
| Injection | Injector Seal |
| Injection | Injector Washer |
| Injection | Injector Return Line |
| Common Rail | Fuel Rail |
| Common Rail | Rail Pressure Sensor |
| Common Rail | Pressure Control Valve |
| Common Rail | Pressure Limiting Valve |
| Fuel Line | Supply Hose |
| Fuel Line | Return Hose |
| Fuel Line | Steel Fuel Pipe |
| Fuel Line | Quick Connector |
| Carburetor | Carburetor Assembly |
| Carburetor | Float |
| Carburetor | Jet |
| Carburetor | Needle Valve |
| Throttle | Throttle Body |
| Throttle | Accelerator Cable |
| Throttle | Electronic Accelerator Pedal |
| Sensor | Fuel Pressure Sensor |
| Sensor | Fuel Temperature Sensor |
| Sensor | Fuel Level Sensor |
| EVAP | Charcoal Canister |
| EVAP | Purge Valve |
| EVAP | Vapor Hose |
| Fuel Additive | Injector Cleaner |
| Fuel Additive | Diesel Additive |
| Fuel Additive | Water Remover |

---

# F. Transmission System

| Category | Subcategory |
|---|---|
| Manual Gearbox | Gearbox Assembly |
| Manual Gearbox | Input Shaft |
| Manual Gearbox | Main Shaft |
| Manual Gearbox | Counter Shaft |
| Manual Gearbox | Gear Set |
| Manual Gearbox | Reverse Gear |
| Synchronizer | Synchronizer Ring |
| Synchronizer | Hub |
| Synchronizer | Sleeve |
| Gear Selection | Shift Fork |
| Gear Selection | Selector Shaft |
| Gear Selection | Gear Lever |
| Gear Selection | Shift Cable |
| Gear Selection | Linkage Bush |
| Transmission Bearing | Input Bearing |
| Transmission Bearing | Output Bearing |
| Transmission Bearing | Needle Bearing |
| Transmission Seal | Input Shaft Seal |
| Transmission Seal | Output Shaft Seal |
| Transmission Seal | Selector Seal |
| Automatic Transmission | Automatic Transmission Assembly |
| Automatic Transmission | Planetary Gear Set |
| Automatic Transmission | Clutch Pack |
| Automatic Transmission | Brake Band |
| Automatic Transmission | Valve Body |
| Automatic Transmission | Oil Pump |
| Automatic Transmission | Transmission Pan |
| Automatic Transmission | Transmission Filter |
| Automatic Control | Shift Solenoid |
| Automatic Control | Pressure Solenoid |
| Automatic Control | Speed Sensor |
| Automatic Control | Range Sensor |
| CVT | CVT Pulley |
| CVT | CVT Belt |
| CVT | CVT Chain |
| CVT | Step Motor |
| CVT | Valve Body |
| Dual Clutch | DCT Clutch Pack |
| Dual Clutch | Mechatronic Unit |
| Transfer Case | Transfer Case Assembly |
| Transfer Case | Transfer Gear |
| Transfer Case | Shift Motor |
| Lubricant | MTF |
| Lubricant | ATF |
| Lubricant | CVT Fluid |
| Lubricant | DCT Fluid |

---

# G. Exhaust System

| Category | Subcategory |
|---|---|
| Exhaust Manifold | Exhaust Manifold |
| Exhaust Manifold | Manifold Gasket |
| Turbo Exhaust | Turbo Outlet Pipe |
| Exhaust Pipe | Front Pipe |
| Exhaust Pipe | Center Pipe |
| Exhaust Pipe | Tail Pipe |
| Flexible Joint | Exhaust Flex Pipe |
| Muffler | Front Muffler |
| Muffler | Center Muffler |
| Muffler | Rear Muffler |
| Catalytic Converter | Three-Way Catalyst |
| Catalytic Converter | Diesel Oxidation Catalyst |
| DPF | Diesel Particulate Filter |
| DPF | Differential Pressure Pipe |
| SCR | SCR Catalyst |
| SCR | DEF Injector |
| SCR | DEF Pump |
| SCR | DEF Tank |
| SCR | DEF Heater |
| EGR | EGR Valve |
| EGR | EGR Cooler |
| EGR | EGR Pipe |
| EGR | EGR Gasket |
| Sensor | Oxygen Sensor |
| Sensor | NOx Sensor |
| Sensor | Exhaust Gas Temperature Sensor |
| Sensor | DPF Pressure Sensor |
| Mounting | Exhaust Hanger |
| Mounting | Rubber Mount |
| Mounting | Exhaust Bracket |
| Clamp | Exhaust Clamp |
| Consumable | DEF / AdBlue |
| Consumable | DPF Cleaner |

---

# H. Steering System

| Category | Subcategory |
|---|---|
| Steering Wheel | Steering Wheel |
| Steering Column | Column Assembly |
| Steering Column | Intermediate Shaft |
| Steering Column | Universal Joint |
| Steering Column | Column Bearing |
| Steering Gear | Steering Rack |
| Steering Gear | Steering Gear Box |
| Steering Gear | Rack End |
| Steering Gear | Rack Boot |
| Steering Linkage | Inner Tie Rod |
| Steering Linkage | Outer Tie Rod |
| Steering Linkage | Drag Link |
| Steering Linkage | Center Link |
| Steering Linkage | Pitman Arm |
| Steering Linkage | Idler Arm |
| Steering Knuckle | Steering Knuckle |
| Steering Knuckle | King Pin |
| Steering Knuckle | King Pin Bush |
| Hydraulic Steering | Power Steering Pump |
| Hydraulic Steering | Reservoir |
| Hydraulic Steering | Pressure Hose |
| Hydraulic Steering | Return Hose |
| Hydraulic Steering | Steering Oil Cooler |
| Electric Steering | EPS Motor |
| Electric Steering | EPS ECU |
| Electric Steering | Torque Sensor |
| Electric Steering | Steering Angle Sensor |
| Seal | Steering Rack Seal Kit |
| Fluid | Power Steering Fluid |

---

# I. Travel Drive / Axle Assembly

| Category | Subcategory |
|---|---|
| Propeller Shaft | Propeller Shaft |
| Propeller Shaft | Slip Yoke |
| Propeller Shaft | Flange Yoke |
| Universal Joint | Universal Joint |
| Center Bearing | Propeller Center Bearing |
| Front Axle | Axle Housing |
| Rear Axle | Axle Housing |
| Axle Shaft | Left Axle Shaft |
| Axle Shaft | Right Axle Shaft |
| Differential | Differential Carrier |
| Differential | Crown Wheel |
| Differential | Pinion Gear |
| Differential | Spider Gear |
| Differential | Side Gear |
| Differential | Differential Case |
| Differential | Pinion Bearing |
| Differential | Carrier Bearing |
| Differential | Pinion Seal |
| Limited Slip | LSD Clutch |
| Limited Slip | LSD Carrier |
| CV Drive | CV Joint |
| CV Drive | CV Boot |
| CV Drive | Drive Shaft |
| Wheel Hub | Hub Assembly |
| Wheel Hub | Wheel Bearing |
| Wheel Hub | Hub Seal |
| Wheel Hub | Wheel Stud |
| Final Drive | Planetary Final Drive |
| Final Drive | Final Drive Motor |
| Final Drive | Reduction Gear |
| Axle Breather | Breather |
| Lubricant | Axle/Differential Oil |

---

# J. Main Frame, Guard & Bogie

| Category | Subcategory |
|---|---|
| Main Frame | Chassis Rail |
| Main Frame | Main Frame Assembly |
| Cross Member | Front Cross Member |
| Cross Member | Center Cross Member |
| Cross Member | Rear Cross Member |
| Subframe | Front Subframe |
| Subframe | Rear Subframe |
| Frame Mount | Body Mount |
| Frame Mount | Cab Mount |
| Frame Mount | Equipment Mount |
| Guard | Engine Guard |
| Guard | Transmission Guard |
| Guard | Fuel Tank Guard |
| Guard | Side Guard |
| Guard | Underrun Guard |
| Guard | Splash Shield |
| Bogie | Bogie Frame |
| Bogie | Bogie Beam |
| Bogie | Bogie Pin |
| Bogie | Bogie Bearing |
| Bogie | Bogie Bush |
| Tow System | Tow Hook |
| Tow System | Tow Eye |
| Tow System | Drawbar |
| Hitch | Pintle Hook |
| Hitch | Fifth Wheel |
| Fastener | Frame Bolt |
| Fastener | High-Tensile Nut |
| Fastener | Washer |
| Corrosion Protection | Anti-Rust Coating |
| Coating | Primer |
| Coating | Chassis Paint |

---

# K. Electrical & Electronic System

Ini sebaiknya dibuat sangat rinci karena item inventory-nya sangat banyak.

| Category | Subcategory |
|---|---|
| Battery | Starter Battery |
| Battery | Auxiliary Battery |
| Battery | Battery Terminal |
| Battery | Battery Cable |
| Battery | Battery Clamp |
| Battery | Battery Tray |
| Charging | Alternator |
| Charging | Voltage Regulator |
| Charging | Alternator Pulley |
| Charging | Alternator Bearing |
| Starting | Starter Motor |
| Starting | Starter Solenoid |
| Starting | Starter Relay |
| Starting | Starter Bendix |
| Wiring Harness | Engine Harness |
| Wiring Harness | Cabin Harness |
| Wiring Harness | Chassis Harness |
| Wiring Harness | Door Harness |
| Cable | Power Cable |
| Cable | Ground Cable |
| Connector | Electrical Connector |
| Connector | Terminal |
| Connector | Connector Housing |
| Connector | Weather Seal |
| Fuse | Mini Fuse |
| Fuse | Standard Fuse |
| Fuse | Maxi Fuse |
| Fuse | Cartridge Fuse |
| Fuse | Fusible Link |
| Relay | Micro Relay |
| Relay | Standard Relay |
| Relay | Power Relay |
| Switch | Ignition Switch |
| Switch | Brake Switch |
| Switch | Clutch Switch |
| Switch | Reverse Switch |
| Switch | Door Switch |
| Switch | Combination Switch |
| Engine Sensor | CKP Sensor |
| Engine Sensor | CMP Sensor |
| Engine Sensor | MAP Sensor |
| Engine Sensor | MAF Sensor |
| Engine Sensor | TPS Sensor |
| Engine Sensor | Knock Sensor |
| Temperature Sensor | Coolant Sensor |
| Temperature Sensor | Intake Sensor |
| Pressure Sensor | Oil Pressure Sensor |
| Pressure Sensor | Fuel Pressure Sensor |
| Pressure Sensor | Boost Pressure Sensor |
| Position Sensor | Accelerator Position Sensor |
| Position Sensor | Steering Angle Sensor |
| Position Sensor | Ride Height Sensor |
| Speed Sensor | Vehicle Speed Sensor |
| Speed Sensor | Wheel Speed Sensor |
| ECU | Engine Control Module |
| ECU | Transmission Control Module |
| ECU | Body Control Module |
| ECU | ABS Module |
| ECU | Airbag Module |
| ECU | Gateway Module |
| Actuator | Solenoid |
| Actuator | Electric Motor |
| Lighting | Headlamp Assembly |
| Lighting | Tail Lamp |
| Lighting | Fog Lamp |
| Lighting | Turn Signal |
| Lighting | Side Marker |
| Lighting | Work Lamp |
| Lighting | Beacon |
| Bulb | Halogen Bulb |
| Bulb | LED Module |
| Horn | Horn |
| Wiper | Wiper Motor |
| Wiper | Wiper Linkage |
| Wiper | Wiper Arm |
| Wiper | Wiper Blade |
| Washer | Washer Pump |
| Washer | Washer Reservoir |
| Washer | Washer Nozzle |
| Instrument | Instrument Cluster |
| Instrument | Gauge |
| Accessory Power | Cigarette Socket |
| Accessory Power | USB Charger |
| Consumable | Cable Tie |
| Consumable | Electrical Tape |
| Consumable | Heat Shrink |
| Consumable | Contact Cleaner |

---

# L. Brake System

| Category | Subcategory |
|---|---|
| Disc Brake | Brake Pad |
| Disc Brake | Brake Disc / Rotor |
| Disc Brake | Brake Caliper |
| Disc Brake | Caliper Piston |
| Disc Brake | Caliper Seal Kit |
| Disc Brake | Caliper Guide Pin |
| Drum Brake | Brake Shoe |
| Drum Brake | Brake Drum |
| Drum Brake | Wheel Cylinder |
| Drum Brake | Return Spring |
| Drum Brake | Adjuster |
| Hydraulic Brake | Master Cylinder |
| Hydraulic Brake | Reservoir |
| Hydraulic Brake | Brake Hose |
| Hydraulic Brake | Brake Pipe |
| Hydraulic Brake | Proportioning Valve |
| Brake Booster | Vacuum Booster |
| Brake Booster | Vacuum Pump |
| Brake Booster | Vacuum Hose |
| ABS | ABS Modulator |
| ABS | ABS Pump |
| ABS | Wheel Speed Sensor |
| ABS | Tone Ring |
| ABS | ABS ECU |
| Parking Brake | Parking Brake Cable |
| Parking Brake | Parking Brake Lever |
| Parking Brake | Parking Brake Shoe |
| EPB | Electric Parking Brake Motor |
| EPB | EPB Module |
| Air Brake | Brake Chamber |
| Air Brake | Spring Brake Chamber |
| Air Brake | Slack Adjuster |
| Air Brake | S-Cam |
| Air Brake | Brake Lining |
| Air Brake | Relay Valve |
| Air Brake | Foot Brake Valve |
| Air Brake | Quick Release Valve |
| Retarder | Hydraulic Retarder |
| Retarder | Electric Retarder |
| Fluid | Brake Fluid |
| Chemical | Brake Cleaner |
| Lubricant | Brake Grease |

---

# M. Suspension System

| Category | Subcategory |
|---|---|
| Shock Absorber | Front Shock |
| Shock Absorber | Rear Shock |
| Strut | MacPherson Strut |
| Strut | Strut Mount |
| Strut | Strut Bearing |
| Coil Spring | Front Coil Spring |
| Coil Spring | Rear Coil Spring |
| Leaf Spring | Main Leaf |
| Leaf Spring | Helper Leaf |
| Leaf Spring | Spring Pack |
| Leaf Spring | Center Bolt |
| Leaf Spring | Spring Clip |
| Leaf Spring | Spring Eye Bush |
| Leaf Spring | Shackle |
| Leaf Spring | U-Bolt |
| Air Suspension | Air Spring |
| Air Suspension | Height Control Valve |
| Air Suspension | Air Suspension Compressor |
| Air Suspension | Air Suspension ECU |
| Control Arm | Upper Control Arm |
| Control Arm | Lower Control Arm |
| Control Arm | Control Arm Bush |
| Ball Joint | Upper Ball Joint |
| Ball Joint | Lower Ball Joint |
| Stabilizer | Stabilizer Bar |
| Stabilizer | Stabilizer Link |
| Stabilizer | Stabilizer Bush |
| Torque Rod | Torque Rod |
| Radius Rod | Radius Rod |
| Panhard Rod | Panhard Rod |
| Torsion Bar | Torsion Bar |
| Suspension Bushing | Rubber Bush |
| Suspension Bushing | Polyurethane Bush |
| Bump Stop | Bump Rubber |
| Height Sensor | Ride Height Sensor |

---

# N. Hydraulic System

| Category | Subcategory |
|---|---|
| Hydraulic Pump | Gear Pump |
| Hydraulic Pump | Piston Pump |
| Hydraulic Pump | Vane Pump |
| Hydraulic Pump | Tandem Pump |
| Hydraulic Motor | Travel Motor |
| Hydraulic Motor | Swing Motor |
| Hydraulic Motor | Fan Motor |
| Hydraulic Cylinder | Boom Cylinder |
| Hydraulic Cylinder | Arm Cylinder |
| Hydraulic Cylinder | Bucket Cylinder |
| Hydraulic Cylinder | Steering Cylinder |
| Hydraulic Cylinder | Lift Cylinder |
| Cylinder Component | Piston Rod |
| Cylinder Component | Piston |
| Cylinder Component | Gland |
| Cylinder Component | Seal Kit |
| Control Valve | Main Control Valve |
| Control Valve | Directional Valve |
| Control Valve | Relief Valve |
| Control Valve | Check Valve |
| Control Valve | Flow Control Valve |
| Solenoid Valve | Hydraulic Solenoid |
| Pilot System | Pilot Valve |
| Pilot System | Pilot Pump |
| Pilot System | Pilot Hose |
| Accumulator | Hydraulic Accumulator |
| Hydraulic Hose | High Pressure Hose |
| Hydraulic Hose | Return Hose |
| Hydraulic Hose | Suction Hose |
| Hydraulic Pipe | Steel Pipe |
| Hydraulic Fitting | Elbow |
| Hydraulic Fitting | Tee |
| Hydraulic Fitting | Adapter |
| Hydraulic Fitting | Quick Coupler |
| Reservoir | Hydraulic Tank |
| Reservoir | Breather |
| Reservoir | Level Gauge |
| Filter | Return Filter |
| Filter | Suction Filter |
| Filter | Pressure Filter |
| Filter | Pilot Filter |
| Cooler | Hydraulic Oil Cooler |
| Sensor | Hydraulic Pressure Sensor |
| Sensor | Oil Temperature Sensor |
| Oil | Hydraulic Oil |
| Chemical | Hydraulic System Cleaner |

---

# O. Pneumatic System

| Category | Subcategory |
|---|---|
| Air Compressor | Compressor Assembly |
| Air Compressor | Compressor Head |
| Air Compressor | Compressor Repair Kit |
| Air Dryer | Air Dryer |
| Air Dryer | Dryer Cartridge |
| Air Dryer | Purge Valve |
| Air Reservoir | Primary Tank |
| Air Reservoir | Secondary Tank |
| Air Reservoir | Wet Tank |
| Air Reservoir | Drain Valve |
| Protection Valve | Four-Circuit Protection Valve |
| Pressure Valve | Pressure Regulator |
| Brake Valve | Foot Brake Valve |
| Brake Valve | Relay Valve |
| Brake Valve | Quick Release Valve |
| Brake Valve | Load Sensing Valve |
| Parking Valve | Hand Brake Valve |
| Air Hose | Nylon Tube |
| Air Hose | Rubber Hose |
| Fitting | Straight Connector |
| Fitting | Elbow |
| Fitting | Tee |
| Fitting | Push-In Connector |
| Pneumatic Cylinder | Air Cylinder |
| Actuator | Pneumatic Actuator |
| Gauge | Air Pressure Gauge |
| Sensor | Air Pressure Sensor |
| Switch | Low Pressure Switch |
| Silencer | Pneumatic Muffler |
| Lubricator | Air Lubricator |
| Filter | Air Line Filter |
| Regulator | FRL Regulator |

---

# P. Swing System

Untuk excavator, crane, rotary equipment, dan heavy machinery.

| Category | Subcategory |
|---|---|
| Swing Motor | Swing Hydraulic Motor |
| Swing Motor | Motor Seal Kit |
| Swing Reduction | Swing Gearbox |
| Swing Reduction | Planetary Gear |
| Swing Reduction | Sun Gear |
| Swing Reduction | Carrier |
| Swing Reduction | Pinion |
| Swing Bearing | Slewing Bearing |
| Swing Bearing | Bearing Bolt |
| Swing Gear | Ring Gear |
| Swing Brake | Brake Disc |
| Swing Brake | Brake Piston |
| Swing Brake | Brake Spring |
| Swing Control | Swing Valve |
| Swing Control | Swing Solenoid |
| Swing Sensor | Swing Position Sensor |
| Swing Sensor | Rotation Sensor |
| Lubrication | Swing Gear Oil |
| Lubrication | Swing Bearing Grease |
| Seal | Swing Gear Seal |
| Seal | O-Ring |
| Hose | Swing Hydraulic Hose |

---

# Q. Undercarriage

Untuk tracked equipment, ini perlu menjadi taxonomy yang cukup dalam.

| Category | Subcategory |
|---|---|
| Track Chain | Track Link |
| Track Chain | Track Link Assembly |
| Track Chain | Track Pin |
| Track Chain | Track Bush |
| Track Shoe | Standard Shoe |
| Track Shoe | Grouser Shoe |
| Track Shoe | Rubber Pad |
| Track Shoe | Track Bolt |
| Track Shoe | Track Nut |
| Bottom Roller | Track Roller |
| Bottom Roller | Single Flange Roller |
| Bottom Roller | Double Flange Roller |
| Carrier Roller | Upper Roller |
| Idler | Front Idler |
| Idler | Idler Shaft |
| Idler | Idler Bearing |
| Sprocket | Sprocket Segment |
| Sprocket | Complete Sprocket |
| Track Adjuster | Adjuster Cylinder |
| Track Adjuster | Grease Valve |
| Recoil | Recoil Spring |
| Recoil | Recoil Rod |
| Guard | Track Guard |
| Guard | Roller Guard |
| Final Drive | Final Drive Assembly |
| Final Drive | Travel Reduction Gear |
| Final Drive | Travel Motor |
| Seal | Floating Seal |
| Seal | Duo Cone Seal |
| Lubricant | Track Grease |
| Lubricant | Final Drive Oil |

---

# R. Wheel & Tyre System

Di sini saya sarankan memisahkan **Component Group = Wheel & Tyre**, sementara Item Type tetap `Tire`, `Rim`, `Sparepart`, atau `Consumable`.

## Tire

| Category | Subcategory |
|---|---|
| Passenger Tire | Summer Tire |
| Passenger Tire | All-Season Tire |
| Passenger Tire | Performance Tire |
| Passenger Tire | Touring Tire |
| SUV Tire | Highway Terrain |
| SUV Tire | All Terrain |
| SUV Tire | Mud Terrain |
| Light Truck Tire | Commercial Van Tire |
| Truck Tire | Steer Tire |
| Truck Tire | Drive Tire |
| Truck Tire | Trailer Tire |
| Truck Tire | All Position Tire |
| Bus Tire | City Bus Tire |
| Bus Tire | Coach Tire |
| Bus Tire | All Position Tire |
| OTR Tire | Earthmover Tire |
| OTR Tire | Loader Tire |
| OTR Tire | Grader Tire |
| Industrial Tire | Pneumatic Forklift Tire |
| Industrial Tire | Solid Tire |
| Construction | Radial Tire |
| Construction | Bias Tire |
| Construction | Solid Tire |
| Tube System | Tubeless Tire |
| Tube System | Tube Type Tire |
| Inner Components | Inner Tube |
| Inner Components | Flap |

## Rim

| Category | Subcategory |
|---|---|
| Steel Rim | Passenger Steel Rim |
| Steel Rim | Light Truck Steel Rim |
| Steel Rim | Truck/Bus Steel Rim |
| Alloy Rim | Cast Alloy Rim |
| Alloy Rim | Forged Alloy Rim |
| Heavy Duty Rim | OTR Rim |
| Multi-Piece Rim | Split Rim |
| Multi-Piece Rim | Lock Ring |
| Multi-Piece Rim | Side Ring |
| Industrial Rim | Forklift Rim |

## Wheel Related Spareparts

| Category | Subcategory |
|---|---|
| Wheel Fastener | Wheel Nut |
| Wheel Fastener | Wheel Bolt |
| Wheel Fastener | Wheel Stud |
| Valve | Tubeless Valve |
| Valve | Truck Valve |
| Valve | Valve Core |
| Valve | Valve Cap |
| TPMS | TPMS Sensor |
| TPMS | TPMS Valve |
| Balance | Wheel Weight |
| Consumable | Tire Patch |
| Consumable | Vulcanizing Cement |
| Consumable | Tire Sealant |
| Consumable | Bead Lubricant |

---

# S. Attachment & Work Equipment

| Category | Subcategory |
|---|---|
| Bucket | General Purpose Bucket |
| Bucket | Heavy Duty Bucket |
| Bucket | Rock Bucket |
| Bucket | Trenching Bucket |
| Bucket | Ditch Cleaning Bucket |
| Bucket | Skeleton Bucket |
| Bucket Component | Bucket Tooth |
| Bucket Component | Tooth Adapter |
| Bucket Component | Side Cutter |
| Bucket Component | Cutting Edge |
| Bucket Component | Heel Shroud |
| Bucket Component | Wear Plate |
| Bucket Component | Bucket Pin |
| Bucket Component | Bucket Bush |
| Hydraulic Breaker | Breaker Assembly |
| Hydraulic Breaker | Chisel |
| Hydraulic Breaker | Through Bolt |
| Hydraulic Breaker | Diaphragm |
| Hydraulic Breaker | Seal Kit |
| Grapple | Mechanical Grapple |
| Grapple | Hydraulic Grapple |
| Fork | Pallet Fork |
| Fork | Fork Tine |
| Blade | Dozer Blade |
| Blade | Cutting Edge |
| Blade | End Bit |
| Auger | Auger Drive |
| Auger | Auger Bit |
| Compactor | Plate Compactor |
| Coupler | Quick Coupler |
| Coupler | Coupler Pin |
| Crane Attachment | Hook |
| Crane Attachment | Sheave |
| Crane Attachment | Wire Rope |
| Crane Attachment | Load Block |
| Hydraulic Attachment | Attachment Hose |
| Hydraulic Attachment | Quick Coupler |
| Hydraulic Attachment | Control Valve |

---

# T. Optional Accessories

| Category | Subcategory |
|---|---|
| Safety | Fire Extinguisher |
| Safety | Warning Triangle |
| Safety | Safety Hammer |
| Safety | First Aid Box |
| Safety | Wheel Chock |
| Safety | Reflective Vest |
| Exterior | Mud Flap |
| Exterior | Side Step |
| Exterior | Roof Rack |
| Exterior | Bull Bar |
| Exterior | Weather Shield |
| Exterior | Mirror Extension |
| Interior | Floor Mat |
| Interior | Seat Cover |
| Interior | Sun Visor |
| Interior | Storage Organizer |
| Audio | Head Unit |
| Audio | Speaker |
| Audio | Amplifier |
| Navigation | Navigation Unit |
| Telematics | GPS Tracker |
| Telematics | MDT |
| Telematics | CAN Adapter |
| Camera | Dash Camera |
| Camera | Reverse Camera |
| Camera | MDVR |
| Camera | Surround Camera |
| Parking Assist | Parking Sensor |
| Communication | Two-Way Radio |
| Lighting | Work Lamp |
| Lighting | Beacon |
| Lighting | Auxiliary Lamp |
| Electrical Accessory | USB Charger |
| Electrical Accessory | Power Inverter |
| Security | Alarm |
| Security | Immobilizer |
| Security | Central Lock Module |
| Storage | Tool Box |
| Storage | Cargo Box |

---

# U. Component Group Tambahan yang Sebaiknya Dibuat

Setelah melihat taxonomy secara mekanikal, saya menyarankan **jangan berhenti pada 20 Component Group awal**.

Ada beberapa kelompok penting yang saat ini tidak mempunyai rumah klasifikasi yang tepat.

## U1. HVAC / Air Conditioning

| Category | Subcategory |
|---|---|
| Compressor | AC Compressor |
| Compressor | Compressor Clutch |
| Compressor | Pulley |
| Condenser | AC Condenser |
| Evaporator | Evaporator Core |
| Expansion | Expansion Valve |
| Expansion | Orifice Tube |
| Receiver | Receiver Dryer |
| Blower | Blower Motor |
| Blower | Blower Resistor |
| Heater | Heater Core |
| Air Distribution | Air Duct |
| Air Distribution | Vent |
| HVAC Control | AC Control Panel |
| HVAC Control | HVAC ECU |
| Sensor | Cabin Temperature Sensor |
| Sensor | Evaporator Sensor |
| Sensor | Pressure Sensor |
| Hose | Refrigerant Hose |
| Seal | AC O-Ring |
| Refrigerant | R134a / R1234yf |
| Lubricant | Compressor Oil |
| Consumable | AC Cleaner |

---

# U2. Body & Cabin

| Category | Subcategory |
|---|---|
| Body Panel | Hood |
| Body Panel | Fender |
| Body Panel | Door Panel |
| Body Panel | Quarter Panel |
| Body Panel | Roof Panel |
| Body Panel | Bumper |
| Door | Door Assembly |
| Door | Door Hinge |
| Door | Door Handle |
| Door | Door Lock |
| Door | Door Seal |
| Window | Window Regulator |
| Window | Window Motor |
| Mirror | Side Mirror |
| Mirror | Mirror Motor |
| Seat | Driver Seat |
| Seat | Passenger Seat |
| Seat | Seat Rail |
| Seat | Seat Mechanism |
| Cabin Mount | Cab Mount |
| Cabin Mount | Cab Mount Bush |
| Hood | Hood Hinge |
| Hood | Hood Lock |
| Consumable | Body Sealant |
| Consumable | Paint |
| Consumable | Adhesive |

---

# U3. Glass & Washer System

| Category | Subcategory |
|---|---|
| Glass | Windshield |
| Glass | Rear Glass |
| Glass | Door Glass |
| Glass | Quarter Glass |
| Glass Hardware | Glass Molding |
| Glass Hardware | Weather Strip |
| Wiper | Wiper Blade |
| Wiper | Wiper Arm |
| Wiper | Wiper Motor |
| Wiper | Wiper Linkage |
| Washer | Washer Pump |
| Washer | Washer Tank |
| Washer | Washer Hose |
| Washer | Washer Nozzle |
| Consumable | Washer Fluid |
| Consumable | Glass Cleaner |

---

# U4. Safety & Restraint System

| Category | Subcategory |
|---|---|
| Seat Belt | Seat Belt Assembly |
| Seat Belt | Pretensioner |
| Airbag | Driver Airbag |
| Airbag | Passenger Airbag |
| Airbag | Side Airbag |
| Airbag | Curtain Airbag |
| Airbag Control | Airbag ECU |
| Airbag Sensor | Crash Sensor |
| Occupancy | Seat Occupancy Sensor |
| Child Safety | ISOFIX Bracket |
| Warning | Seat Belt Switch |

---

# U5. EV High Voltage System

Apabila OptiFleet akan mendukung EV, ini wajib dipisah.

| Category | Subcategory |
|---|---|
| Traction Battery | HV Battery Pack |
| Battery Module | Battery Module |
| Battery Cell | Cell |
| Battery Management | BMS |
| HV Junction | HV Junction Box |
| Contactor | Main Contactor |
| Fuse | HV Fuse |
| Service Disconnect | MSD |
| HV Cable | Orange HV Cable |
| HV Connector | High Voltage Connector |
| Charging | Onboard Charger |
| Charging | Charging Port |
| Charging | Charging Cable |
| DC/DC | DC/DC Converter |
| Inverter | Traction Inverter |
| Electric Motor | Traction Motor |
| Reduction Gear | EV Reduction Gear |
| Electric AC | Electric Compressor |
| HV Heater | PTC Heater |
| Battery Cooling | Cooling Plate |
| Battery Cooling | Coolant Pump |
| Battery Cooling | Battery Chiller |
| Sensor | Battery Temperature Sensor |
| Sensor | Isolation Sensor |

---

# V. Cara Saya Akan Membentuk Master Data

Dengan taxonomy di atas, contoh data riil tidak lagi dibuat seperti:

> Sparepart → Brake → Brake Pad

tetapi:

```text
Item Type       : Sparepart
Component Group : Brake System
Category        : Disc Brake
Subcategory     : Brake Pad
Application     : Front Axle
```

Untuk item lain:

```text
Item Type       : Sparepart
Component Group : Engine
Category        : Valve Train
Subcategory     : Exhaust Valve
```

atau:

```text
Item Type       : Consumable
Component Group : Lubrication System
Category        : Engine Lubricant
Subcategory     : Fully Synthetic Engine Oil
```

atau:

```text
Item Type       : Tire
Component Group : Wheel & Tyre System
Category        : Truck Tire
Subcategory     : Drive Tire
```

Ini jauh lebih berguna untuk mekanik karena pencarian dapat dilakukan:

**Brake System → Disc Brake → Brake Pad**

dan bagi warehouse:

**Item Type = Sparepart → fast moving → Brake Pad → compatible vehicle.**

---

# W. Level Hierarki yang Saya Rekomendasikan

Untuk implementasi OptiFleet, saya menyarankan maksimal **empat level klasifikasi teknis**:

```text
L1 Component Group
        ↓
L2 Category / Assembly
        ↓
L3 Subcategory / Component Family
        ↓
L4 Item / SKU
```

Contoh Engine:

```text
Engine
└── Valve Train
    ├── Intake Valve
    ├── Exhaust Valve
    ├── Valve Guide
    ├── Valve Seat
    ├── Valve Spring
    ├── Valve Stem Seal
    ├── Rocker Arm
    ├── Rocker Shaft
    └── Tappet
```

Contoh Brake:

```text
Brake System
├── Disc Brake
│   ├── Brake Pad
│   ├── Brake Disc
│   ├── Brake Caliper
│   ├── Caliper Piston
│   ├── Guide Pin
│   └── Repair Kit
│
├── Drum Brake
│   ├── Brake Shoe
│   ├── Brake Drum
│   ├── Wheel Cylinder
│   └── Adjuster
│
├── ABS
│   ├── ABS Modulator
│   ├── Wheel Speed Sensor
│   └── Tone Ring
│
└── Air Brake
    ├── Brake Chamber
    ├── Slack Adjuster
    ├── S-Cam
    └── Relay Valve
```

Menurut saya, **ini sudah mendekati struktur master yang layak dipakai sebagai seed master OptiFleet**, bukan sekadar daftar konseptual.

Tahap berikut yang paling penting adalah membuatnya menjadi **satu Master Classification Matrix lengkap** dengan kolom:

**Component Group | Category | Subcategory | Item Type Allowed | Serialized | Maintainable | Repairable | Stockable | UOM Default | Vehicle Type Applicability | Notes**

Dengan format itu, daftar di atas bisa langsung diterjemahkan menjadi database seeder, dynamic form Item Master, dan business rule OptiFleet.