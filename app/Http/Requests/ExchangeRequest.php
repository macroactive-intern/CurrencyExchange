<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ExchangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_currency' => ['required', 'string', 'max:50'],
            'to_currency'   => ['required', 'string', 'max:50', 'different:from_currency'],
            'amount'        => ['required', 'numeric', 'min:0.01'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $key = "{$this->from_currency}_to_{$this->to_currency}";

                if (config("exchange.rates.{$key}") === null) {
                    $validator->errors()->add(
                        'from_currency',
                        'Unsupported exchange pair.'
                    );
                }
            },
        ];
    }
}
