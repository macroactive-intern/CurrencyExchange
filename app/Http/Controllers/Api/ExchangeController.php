<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExchangeRequest;
use App\Services\ExchangeService;
use Illuminate\Http\JsonResponse;

class ExchangeController extends Controller
{
    public function store(ExchangeRequest $request, ExchangeService $exchangeService): JsonResponse
    {
        $result = $exchangeService->exchange(
            $request->user(),
            $request->from_currency,
            $request->to_currency,
            (float) $request->amount,
        );

        return response()->json($result);
    }
}
