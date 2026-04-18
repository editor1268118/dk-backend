<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreProfessionalServiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true; // We handle authorization in the controller
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'service_category_id' => 'required|exists:service_categories,id',
            'title'               => 'required|string|max:255',
            'short_description'   => 'nullable|string|max:500',
            'description'         => 'nullable|string',
            'price'               => 'nullable|numeric|min:0',
            'pricing_type'        => 'nullable|string|max:50',
            'duration_minutes'    => 'nullable|integer|min:1',
            'is_active'           => 'nullable|boolean',
        ];
    }
}
