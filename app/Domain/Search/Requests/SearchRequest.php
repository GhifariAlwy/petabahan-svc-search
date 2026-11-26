<?php

namespace App\Domain\Search\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_m' => ['nullable', 'integer', 'min:1000', 'max:25000'],
            'save_as_shopping_list' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.material_id' => ['required', 'string'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit' => ['required', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'latitude.required' => 'Koordinat garis lintang (latitude) wajib diisi.',
            'longitude.required' => 'Koordinat garis bujur (longitude) wajib diisi.',
            'radius_m.min' => 'Radius pencarian minimal 1.000 meter (1 km).',
            'radius_m.max' => 'Radius pencarian maksimal 25.000 meter (25 km).',
            'items.required' => 'Daftar bahan yang dicari tidak boleh kosong.',
            'items.min' => 'Minimal sertakan 1 bahan dalam pencarian.',
            'items.*.material_id.required' => 'ID bahan baku wajib disertakan.',
            'items.*.qty.min' => 'Jumlah kuantitas bahan minimal 0.001.',
        ];
    }
}
