<?php

namespace Moox\Sync\Services;

use Illuminate\Support\Facades\DB;
use Moox\Sync\Models\Platform;

class PlatformRelationService
{
    public function syncPlatformsForModel($model, array $platformIds): void
    {
        $modelType = get_class($model);
        $modelId = $model->getKey();

        DB::table('model_platform')
            ->where('model_type', $modelType)
            ->where('model_id', $modelId)
            ->delete();

        $insertData = array_map(function ($platformId) use ($modelType, $modelId) {
            return [
                'model_type' => $modelType,
                'model_id' => $modelId,
                'platform_id' => $platformId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }, $platformIds);

        DB::table('model_platform')->insert($insertData);
    }

    public function getPlatformsForModel($model)
    {
        $modelType = get_class($model);
        $modelId = $model->getKey();

        $platformIds = DB::table('model_platform')
            ->where('model_type', $modelType)
            ->where('model_id', $modelId)
            ->pluck('platform_id');

        return Platform::whereIn('id', $platformIds)->get();
    }

    public function addPlatformToModel($model, Platform $platform)
    {
        DB::table('model_platform')->updateOrInsert([
            'model_type' => get_class($model),
            'model_id' => $model->getKey(),
            'platform_id' => $platform->id,
        ]);
    }

    public function removePlatformFromModel($model, Platform $platform)
    {
        DB::table('model_platform')->where([
            'model_type' => get_class($model),
            'model_id' => $model->getKey(),
            'platform_id' => $platform->id,
        ])->delete();
    }

    public function modelHasPlatform($model, Platform $platform)
    {
        return DB::table('model_platform')->where([
            'model_type' => get_class($model),
            'model_id' => $model->getKey(),
            'platform_id' => $platform->id,
        ])->exists();
    }
}