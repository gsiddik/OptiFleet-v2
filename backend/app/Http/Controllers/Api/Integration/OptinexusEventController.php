<?php

namespace App\Http\Controllers\Api\Integration;

use App\Domain\Integration\Master\VehicleDrivers;
use App\Domain\Integration\Master\VehicleMaster;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives events from the OptiNexus event broker (vehicle master data from OptiDeli). Authenticated by the webhook signature
 * only (no session, no CSRF): `Opti-Webhook-Signature: v1=hex(HMAC-SHA256(secret, "<id>.<timestamp>.<raw body>"))` with a timestamp
 * inside the tolerance. The tenant comes from the event's OptiNexus tenant, never from the request. A deployment without a secret
 * refuses everything.
 */
class OptinexusEventController extends Controller
{
    public function receive(Request $request, VehicleMaster $master, VehicleDrivers $drivers): JsonResponse
    {
        $secret = (string) config('optinexus.events.webhook_secret');
        $id = (string) $request->header('Opti-Webhook-Id');
        $timestamp = (string) $request->header('Opti-Webhook-Timestamp');
        $signature = (string) $request->header('Opti-Webhook-Signature');

        $valid = $secret !== '' && $id !== '' && ctype_digit($timestamp)
            && abs(time() - (int) $timestamp) <= (int) config('optinexus.events.webhook_tolerance_seconds')
            && str_starts_with($signature, 'v1=')
            && hash_equals(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$request->getContent(), $secret), substr($signature, 3));
        if (! $valid) {
            return response()->json(['message' => 'Invalid signature.', 'code' => 'WEBHOOK_SIGNATURE_INVALID'], 401);
        }

        $event = json_decode($request->getContent(), true);
        if (! is_array($event)) {
            return response()->json(['message' => 'Invalid body.', 'code' => 'WEBHOOK_BODY_INVALID'], 400);
        }

        $result = ($event['event_type'] ?? null) === VehicleDrivers::RECEIVED ? $drivers->apply($event) : $master->apply($event);

        return response()->json(['result' => $result], 202);
    }
}
