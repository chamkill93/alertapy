<?php

namespace App\Http\Requests;

use App\Enums\IncidentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IncidentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['categories', 'status'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([
                    $key => array_values(array_filter(array_map('trim', explode(',', $this->input($key))))),
                ]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'categories' => ['sometimes', 'array', 'max:20'],
            'categories.*' => ['string', 'max:80', 'distinct'],
            'period' => ['sometimes', Rule::in(['now', '1h', '6h', '24h', '7d', 'historical'])],
            'bbox' => ['sometimes', 'string', 'max:100'],
            'city' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', 'array', 'max:10'],
            'status.*' => ['string', Rule::enum(IncidentStatus::class), 'distinct'],
            'source' => ['sometimes', 'string', 'max:100'],
            'road' => ['sometimes', 'string', 'max:32'],
            'near_lat' => ['sometimes', 'numeric', 'between:-27.7,-19.1', 'required_with:near_lng,radius_km'],
            'near_lng' => ['sometimes', 'numeric', 'between:-62.7,-54.2', 'required_with:near_lat,radius_km'],
            'radius_km' => ['sometimes', 'numeric', Rule::in([1, 3, 5, 10, 25]), 'required_with:near_lat,near_lng'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('alertapy.max_incidents_per_request')],
            'page' => ['sometimes', 'integer', 'min:1'],
            'pagination' => ['sometimes', Rule::in(['offset', 'cursor'])],
            'cursor' => ['sometimes', 'string', 'max:2048'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->filled('bbox') && $this->bboxCoordinates() === null) {
                    $validator->errors()->add(
                        'bbox',
                        'El bounding box debe usar south,west,north,east con coordenadas válidas.',
                    );
                }
            },
        ];
    }

    /** @return array{south: float, west: float, north: float, east: float}|null */
    public function bboxCoordinates(): ?array
    {
        if (! $this->filled('bbox')) {
            return null;
        }

        $parts = array_map('trim', explode(',', (string) $this->input('bbox')));

        if (count($parts) !== 4 || array_filter($parts, 'is_numeric') !== $parts) {
            return null;
        }

        [$south, $west, $north, $east] = array_map('floatval', $parts);

        if ($south < -90 || $north > 90 || $west < -180 || $east > 180 || $south >= $north || $west >= $east) {
            return null;
        }

        return compact('south', 'west', 'north', 'east');
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            ...$this->safe()->only(['categories', 'period', 'city', 'status', 'source', 'road', 'near_lat', 'near_lng', 'radius_km']),
            'bbox' => $this->bboxCoordinates(),
        ];
    }
}
