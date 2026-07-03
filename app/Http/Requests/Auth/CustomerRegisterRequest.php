<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CustomerRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => 'required|string|max:255',
            'age'      => 'nullable|integer|min:12|max:100',
            'email'    => 'required|email|unique:users,email',
            'phone'    => 'nullable|string|unique:users,phone',
            'password' => 'required|string|min:6|confirmed',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'      => __('auth.name_required'),
            'age.required'       => __('auth.age_required'),
            'email.email'        => __('auth.email_invalid'),
            'email.unique'       => __('auth.email_unique'),
            'phone.unique'       => __('auth.phone_unique'),
            'password.required'  => __('auth.password_required'),
            'password.confirmed' => __('auth.password_mismatch'),
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => 0,
            'message' => __('auth.validation_failed'),
            'result'  => ['errors' => $validator->errors()],
        ], 422));
    }
}
