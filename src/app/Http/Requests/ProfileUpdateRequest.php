<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:20',
                'regex:/^[\pL0-9\s\-._]+$/u',
            ],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:120',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'El nombre de usuario solo puede contener letras, números, espacios y guiones.',
            'name.min' => 'El nombre de usuario debe contener al menos 2 caracteres.',
            'name.max' => 'El nombre de usuario no puede exceder los 20 caracteres.',
            'email.email' => 'Debes ingresar un correo electrónico válido.',
            'email.unique' => 'Este correo electrónico ya está registrado por otro usuario.',
        ];
    }
}
