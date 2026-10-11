<?php

namespace App\Http\Controllers\Api\Integration;

use App\Domain\Integration\AccountingFeed\AccountingFeedService;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateAccountingFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only accounting feed for OptiEntry (docs/integration/ACCOUNTING_FEED.md). The tenant is the one bound to the
 * machine credential by AuthenticateAccountingFeed; no request parameter can select or change it.
 */
class AccountingFeedController extends Controller
{
    public function __construct(private readonly AccountingFeedService $feed) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'after' => ['nullable', 'regex:/^\d{1,18}$/'],
            'limit' => ['nullable', 'integer', 'between:1,200'],
        ]);

        $page = $this->feed->page(
            (string) $request->attributes->get(AuthenticateAccountingFeed::TENANT_ATTRIBUTE),
            (int) ($validated['after'] ?? 0),
            (int) ($validated['limit'] ?? 100),
        );

        return response()->json($page)->header('Cache-Control', 'no-store');
    }
}
