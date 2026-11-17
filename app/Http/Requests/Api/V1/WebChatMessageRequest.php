<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class WebChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'min:9', 'max:25'],
            'farmer_name' => ['nullable', 'string', 'max:120'],
            'message_text' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'photo_base64' => ['nullable', 'string'],
            'voice_note' => ['nullable', 'file', 'max:10240'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'language' => ['nullable', 'string', 'in:en,sn,nd'],
        ];
    }
}
