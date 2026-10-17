<?php

namespace Dgtlss\Clearance\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

class OpenOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'min:100']];
    }
}
