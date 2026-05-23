<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceBookingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true; // Auth handled by middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'platform_service_id' => 'required|integer|exists:platform_services,id',
            'preferred_date' => 'required|date|after_or_equal:today',
            'preferred_time' => 'required|string|max:100',
            'customer_phone' => 'required|string|max:20',
            'service_country' => 'nullable|string|max:100',
            'service_state' => 'nullable|string|max:100',
            'service_city' => 'nullable|string|max:100',
            'service_pincode' => 'nullable|string|max:50',
            'service_address_line_1' => 'nullable|string|max:500',
            'service_address_line_2' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:2000',
        ];
    }

    /**
     * Custom error messages.
     *
     * @return array<string, string>
     */
    public function messages()
    {
        return [
            'platform_service_id.required' => 'Please select a service.',
            'platform_service_id.exists' => 'The selected service does not exist.',
            'preferred_date.required' => 'Please select a preferred date.',
            'preferred_date.after_or_equal' => 'Preferred date must be today or in the future.',
            'preferred_time.required' => 'Please select a preferred time slot.',
            'customer_phone.required' => 'Please provide a valid contact number.',
        ];
    }
}
