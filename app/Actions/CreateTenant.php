<?php

namespace App\Actions;

use App\Models\Tenant;
use App\Services\CurrentLandlord;
use App\Services\NumberGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * A new tenant, with their photo, ID scan and LC letter if given. Shared by
 * the web portal and the mobile app's API.
 *
 * $data is StoreTenantRequest's validated data; $files are keyed photo / id_scan / lc_letter.
 *
 * @param  array<string, UploadedFile|null>  $files
 */
class CreateTenant
{
    public const FILE_FOLDERS = ['photo' => 'tenants/photos', 'id_scan' => 'tenants/id-scans', 'lc_letter' => 'tenants/lc-letters'];

    public function __invoke(array $data, array $files = []): Tenant
    {
        foreach (self::FILE_FOLDERS as $field => $folder) {
            unset($data[$field]);
            if (($files[$field] ?? null) instanceof UploadedFile) {
                $data[$field] = $files[$field]->store($folder, 'public');
            }
        }

        return DB::transaction(function () use ($data) {
            $tenant = Tenant::create($data);
            $tenant->update(['number' => app(NumberGenerator::class)->next(CurrentLandlord::id(), 'tenant', 'TEN')]);

            return $tenant;
        });
    }
}
