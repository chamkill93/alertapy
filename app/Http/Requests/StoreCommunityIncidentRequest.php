<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommunityIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['category_slug', 'title', 'summary', 'location_text', 'source_link'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'category_slug' => [
                'required',
                'string',
                Rule::exists('categories', 'slug')->where(fn ($query) => $query->where('enabled', true)),
            ],
            'title' => ['required', 'string', 'min:5', 'max:220'],
            'summary' => ['required', 'string', 'min:10', 'max:3000'],
            'location_text' => ['required', 'string', 'min:3', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-27.8,-19.1'],
            'longitude' => ['required', 'numeric', 'between:-62.9,-54.1'],
            'source_link' => ['nullable', 'url:http,https', 'max:2048'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_slug.required' => 'Seleccioná el tipo de incidente.',
            'category_slug.exists' => 'El tipo de incidente seleccionado no está disponible.',
            'title.required' => 'Escribí un título para el incidente.',
            'summary.required' => 'Describí brevemente qué está ocurriendo.',
            'location_text.required' => 'Agregá una referencia del lugar.',
            'latitude.required' => 'Marcá la ubicación en el mapa.',
            'longitude.required' => 'Marcá la ubicación en el mapa.',
            'source_link.url' => 'El enlace debe comenzar con http:// o https://.',
            'photo.image' => 'La evidencia debe ser una imagen válida.',
            'photo.mimes' => 'La foto debe estar en formato JPG, PNG o WEBP.',
            'photo.max' => 'La foto no puede superar los 5 MB.',
        ];
    }
}
