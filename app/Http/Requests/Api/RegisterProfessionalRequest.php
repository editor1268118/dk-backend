<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class RegisterProfessionalRequest extends FormRequest
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
            'professional_title' => 'required|string|max:255',
            'business_name'      => 'nullable|string|max:255',
            'category'           => 'required|string|max:255',
            'bio'                => 'nullable|string',
            'experience_years'   => 'nullable|integer|min:0|max:100',
        ];
    }
}
