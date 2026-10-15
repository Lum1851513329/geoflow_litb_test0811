<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Services\GeoFlow\LitbAssets\LitbQuarterlyBriefImporter;
use Illuminate\Database\Seeder;

final class LitbQuarterlyBriefSeeder extends Seeder
{
    public function run(LitbQuarterlyBriefImporter $importer): void
    {
        $adminId = Admin::query()->whereIn('role', ['super_admin', 'superadmin'])->orderBy('id')->value('id');
        $importer->import($adminId === null ? null : (int) $adminId);
    }
}
