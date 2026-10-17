<?php

namespace Dgtlss\Clearance\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('order'));
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'min:100']];
    }
}
