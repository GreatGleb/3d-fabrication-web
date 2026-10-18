<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Tiny shared content operation to prove the boundary, not a CMS implementation. */
class ContentPublisher
{
    public function publish(User $admin, string $locale, string $title, ?int $id = null, int $expectedRevision = 0): int
    {
        if (! $admin->is_admin) {
            throw new AuthorizationException;
        }
        if (! in_array($locale, ['lt', 'en'], true) || trim($title) === '' || mb_strlen($title) > 255) {
            throw ValidationException::withMessages(['content' => 'A translated title in LT or EN is required.']);
        }

        return DB::transaction(function () use ($locale, $title, $id, $expectedRevision) {
            $data = ['locale' => $locale, 'title' => $title, 'revision' => $expectedRevision + 1, 'published_at' => now()];
            if ($id === null) {
                return DB::table('probe_contents')->insertGetId($data);
            }
            $record = DB::table('probe_contents')->whereId($id)->lockForUpdate()->first();
            if (! $record || $record->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['revision' => 'Content changed; reload before publishing.']);
            }
            DB::table('probe_contents')->whereId($id)->update($data);

            return $id;
        });
    }
}
