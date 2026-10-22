<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VoteCommunityIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vote' => ['required', Rule::in(['positive', 'negative'])],
            'voter_token' => ['required', 'string', 'min:20', 'max:100'],
        ];
    }
}
