<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExchangeRequest;
use App\Services\ExchangeService;
use Illuminate\Http\JsonResponse;

class ExchangeController extends Controller
{
    public function store(ExchangeRequest $request, ExchangeService $exchangeService): JsonResponse
    {
        try {
            $result = $exchangeService->exchange(
                $request->user(),
                $request->from_currency,
                $request->to_currency,
                (float) $request->amount,
            );

            return response()->json([
                'deducted' => $result['from_amount'],
                'credited' => $result['to_amount'],
                'fee'      => $result['fee'],
            ]);
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
