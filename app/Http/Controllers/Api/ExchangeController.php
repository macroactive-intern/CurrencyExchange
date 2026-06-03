<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Services\ExchangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ExchangeController extends Controller
{
    public function __construct(private ExchangeService $service) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from'   => ['required', 'string', Rule::in(['gold', 'gems'])],
            'to'     => ['required', 'string', Rule::in(['gold', 'gems'])],
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        if ($validated['from'] === $validated['to']) {
            return response()->json(
                ['message' => 'Cannot exchange a currency for itself.'],
                422
            );
        }

        try {
            $result = $this->service->exchange(
                $request->user(),
                $validated['from'],
                $validated['to'],
                $validated['amount']
            );

            return response()->json($result);
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
