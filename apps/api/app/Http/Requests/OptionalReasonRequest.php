<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OptionalReasonRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }

    public function reason(): ?string
    {
        $reason = trim((string) $this->validated('reason'));

        return $reason === '' ? null : $reason;
    }
}
