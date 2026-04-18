<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAvailabilitySlotRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'service_id'       => 'required|exists:services,id',
            'available_date'   => 'required|date',
            'start_time'       => 'required|date_format:H:i',
            'end_time'         => 'required|date_format:H:i|after:start_time',
            'max_bookings'     => 'nullable|integer|min:1',
            'is_available'     => 'nullable|boolean',
            'notes'            => 'nullable|string',
        ];
    }
}
