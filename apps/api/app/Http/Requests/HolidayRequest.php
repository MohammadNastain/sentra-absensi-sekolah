<?php

namespace App\Http\Requests;

use App\Models\Holiday;
use Closure;
use Illuminate\Contracts\Validation\Validator;

/**
 * POST dan PATCH /holidays. Rentang tanggal inklusif; pada PATCH tanggal yang tidak dikirim
 * dibandingkan dengan nilai tersimpan.
 */
class HolidayRequest extends SchoolRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => $this->optionalOnUpdate(['required', 'string', 'max:150']),
            'start_date' => $this->optionalOnUpdate(['required', 'date_format:Y-m-d']),
            'end_date' => $this->optionalOnUpdate(['required', 'date_format:Y-m-d']),
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['start_date', 'end_date'])) {
                return;
            }

            $id = $this->route('holiday');
            $stored = $this->isUpdate() && is_string($id) ? Holiday::query()->find($id) : null;
            $start = $this->input('start_date', $stored?->start_date->toDateString());
            $end = $this->input('end_date', $stored?->end_date->toDateString());

            if (is_string($start) && is_string($end) && $end < $start) {
                $validator->errors()->add('end_date', 'Tanggal selesai tidak boleh sebelum tanggal mulai.');
            }
        }];
    }
}
