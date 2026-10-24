<?php

namespace App\Http\Controllers\Api\Integration;

use App\Domain\Vehicle\Services\VehicleAvailabilityQuery;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vehicle availability for a transport system (docs/integration/VEHICLE_AVAILABILITY.md). The tenant is the one bound to the
 * machine credential by AuthenticateAccountingFeed; no request parameter can select or change it.
 */
class VehicleAvailabilityController extends Controller
{
    public function __construct(private readonly VehicleAvailabilityQuery $availability) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle' => ['nullable', 'uuid', 'required_without:registration_number'],
            'registration_number' => ['nullable', 'string', 'max:30', 'required_without:vehicle'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from']) : CarbonImmutable::now();
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to']) : $from;

        $answer = $this->availability->answer($validated['vehicle'] ?? null, $validated['registration_number'] ?? null, $from, $to);
        if ($answer === null) {
            return response()->json(['message' => 'Vehicle not found.', 'code' => 'VEHICLE_NOT_FOUND'], 404);
        }

        return response()->json(['data' => $answer])->header('Cache-Control', 'no-store');
    }
}
