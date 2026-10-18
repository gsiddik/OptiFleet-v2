<?php

namespace App\Http\Support;

use App\Domain\Integration\Optinexus\TelematicsReviewPending;
use App\Domain\Integration\Optinexus\TelematicsReviewRejected;
use App\Domain\Shared\Support\Messages;
use App\Domain\Vehicle\Services\OdometerRejected;
use Illuminate\Http\JsonResponse;

/**
 * API answer for an odometer value or telematics decision that was refused: the English `code` is stable, the message follows the
 * request language, and `errors.<field>` lets a form show it next to the input.
 */
final class OdometerErrors
{
    private const MESSAGES = [
        OdometerRejected::INVALID => 'errors.http.odometerInvalid',
        OdometerRejected::FUTURE_TIME => 'errors.http.odometerFutureTime',
        OdometerRejected::BELOW_CURRENT => 'errors.http.odometerBelowCurrent',
        OdometerRejected::TIME_CONFLICT => 'errors.http.odometerTimeConflict',
        OdometerRejected::IDEMPOTENCY_CONFLICT => 'errors.http.odometerIdempotencyConflict',
        TelematicsReviewRejected::REASON_REQUIRED => 'errors.http.odometerReasonRequired',
        TelematicsReviewRejected::INVALID_DECISION => 'errors.http.telematicsDecisionInvalid',
        TelematicsReviewRejected::NOTE_REQUIRED => 'errors.http.telematicsNoteRequired',
        TelematicsReviewRejected::NOT_UNDER_REVIEW => 'errors.http.telematicsNotUnderReview',
        TelematicsReviewRejected::CALIBRATION_REQUIRED => 'errors.http.telematicsCalibrationRequired',
        TelematicsReviewPending::CODE => 'errors.http.telematicsReviewPending',
        'TELEMATICS_UNAVAILABLE' => 'errors.http.telematicsUnavailable',
        'TELEMATICS_NOT_LINKED' => 'errors.http.telematicsNotLinked',
    ];

    public static function response(string $code, int $status, string $field, array $context = []): JsonResponse
    {
        $message = Messages::localized(self::MESSAGES[$code] ?? 'errors.http.odometerInvalid', [
            'current' => isset($context['current_odometer']) ? (string) $context['current_odometer'] : '',
        ]);

        return response()->json(['message' => $message, 'code' => $code, 'errors' => [$field => [$message]]] + ($context !== [] ? ['context' => $context] : []), $status);
    }

    public static function fromRejected(OdometerRejected|TelematicsReviewRejected $e, string $field): JsonResponse
    {
        return self::response($e->errorCode, $e->status, $field, $e->context);
    }
}
