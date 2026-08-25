<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'clientId' => ['required', 'string', 'max:100'],
            'changes' => ['present', 'array', 'max:500'],
            'changes.*.id' => ['required', 'string', 'max:100'],
            'changes.*.table' => ['required', 'string', 'in:categories,equipment,borrowings'],
            'changes.*.action' => ['required', 'string', 'in:INSERT,UPDATE,DELETE'],
            'changes.*.docId' => ['required', 'string', 'uuid'],
            'changes.*.payload' => ['present', 'array'],
            'changes.*.clientTimestamp' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'changes.*.table.in' => 'changes.:index.table must be categories, equipment, or borrowings.',
            'changes.*.docId.uuid' => 'changes.:index.docId must be a UUID.',
            'changes.max' => 'Too many changes in one push (max 500).',
        ];
    }

    /** Normalised payload handed to the SyncService. */
    public function toPushPayload(): array
    {
        $validated = $this->validated();

        return [
            'clientId' => $validated['clientId'],
            'changes' => array_map(fn (array $change) => [
                'id' => $change['id'],
                'table' => $change['table'],
                'action' => $change['action'],
                'docId' => $change['docId'],
                'payload' => $change['payload'] ?? [],
                'clientTimestamp' => $change['clientTimestamp'],
            ], $validated['changes']),
        ];
    }
}
