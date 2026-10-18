<?php

namespace Comparison;

use App\Models\StlPart;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StlIntake
{
    public static function unit(array $parameters): int
    {
        return $parameters['material'] === 'PLA' ? 5000 : 7000;
    }

    public function store(User $buyer, array $item): StlPart
    {
        $file = $item['file'];
        $parameters = $item['parameters'];
        if (strtolower($file->getClientOriginalExtension()) !== 'stl' || $file->getSize() > 25 * 1024 * 1024 || ! in_array($parameters['material'] ?? null, ['PLA', 'PETG'], true) || ! in_array($parameters['layer_mm'] ?? null, [0.1, 0.2, 0.3], true) || ! is_int($parameters['quantity'] ?? null) || $parameters['quantity'] < 1 || $parameters['quantity'] > 100) {
            throw ValidationException::withMessages(['file' => 'Valid STL, material, layer and quantity required.']);
        }
        $path = $file->storeAs('probe-stl', Str::uuid().'.stl', 'local');
        try {
            return StlPart::create(['user_id' => $buyer->id, 'original_name' => basename($file->getClientOriginalName()), 'path' => $path, 'sha256' => hash_file('sha256', Storage::disk('local')->path($path)), 'parameters' => $parameters, 'estimate' => self::unit($parameters) * $parameters['quantity']]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
    }
}
