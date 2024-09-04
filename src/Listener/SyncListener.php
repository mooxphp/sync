<?php

namespace Moox\Sync\Listener;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Moox\Core\Traits\LogLevel;
use Moox\Sync\Jobs\DeferredSyncJob;
use Moox\Sync\Models\Platform;
use Moox\Sync\Models\Sync;
use Moox\Sync\Services\SyncService;

class SyncListener
{
    use LogLevel;

    protected $currentPlatform;

    protected $syncService;

    public function __construct(SyncService $syncService)
    {
        $this->syncService = $syncService;
        $this->setCurrentPlatform();
        $this->logDebug('Moox Sync: SyncListener constructed');
    }

    protected function setCurrentPlatform()
    {
        $domain = request()->getHost();
        $this->logDebug('Moox Sync: Setting current platform for domain', ['domain' => $domain]);

        try {
            $this->currentPlatform = Platform::where('domain', $domain)->first();

            if ($this->currentPlatform) {
                $this->logDebug('Moox Sync: Current platform set', ['platform' => $this->currentPlatform->id]);
            } else {
                $this->logDebug('Moox Sync: Platform not found for domain', ['domain' => $domain]);
            }
        } catch (QueryException $e) {
            Log::error('Moox Sync: Database error occurred while querying for domain', ['domain' => $domain, 'error' => $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('Moox Sync: An unexpected error occurred', ['error' => $e->getMessage()]);
        }
    }

    public function registerListeners()
    {
        $this->logDebug('Moox Sync: Registering listeners');
        if ($this->currentPlatform) {
            $syncsToListen = Sync::where('source_platform_id', $this->currentPlatform->id)->get();
            foreach ($syncsToListen as $sync) {
                $this->registerModelListeners($sync->source_model);
            }
            $this->logDebug('Moox Sync: Listeners registered', ['models' => $syncsToListen->pluck('source_model')]);
        } else {
            $this->logDebug('Moox Sync: No listeners registered - current platform not set');
        }
    }

    protected function registerModelListeners($modelClass)
    {
        $this->logDebug('Moox Sync: Registering listeners for model', ['model' => $modelClass]);

        Event::listen("eloquent.created: {$modelClass}", function ($model) use ($modelClass) {
            $this->logDebug('Moox Sync: Created event triggered', ['model' => $modelClass, 'id' => $model->id]);
            $this->handleModelEvent($model, 'created');
        });

        Event::listen("eloquent.updated: {$modelClass}", function ($model) use ($modelClass) {
            $this->logDebug('Moox Sync: Updated event triggered', ['model' => $modelClass, 'id' => $model->id]);
            $this->handleModelEvent($model, 'updated');
        });

        Event::listen("eloquent.deleted: {$modelClass}", function ($model) use ($modelClass) {
            $this->logDebug('Moox Sync: Deleted event triggered', ['model' => $modelClass, 'id' => $model->id]);
            $this->handleModelEvent($model, 'deleted');
        });
    }

    protected function handleModelEvent($model, $eventType)
    {
        if (! $this->currentPlatform) {
            $this->logDebug('Moox Sync: Model event ignored - current platform not set', ['model' => get_class($model), 'id' => $model->id, 'event' => $eventType]);

            return;
        }

        $this->logDebug('Dispatching DeferredSyncJob', [
            'model' => get_class($model),
            'id' => $model->id,
            'event' => $eventType,
            'platform' => $this->currentPlatform->id,
        ]);

        DeferredSyncJob::dispatch($model->id, get_class($model), $eventType, $this->currentPlatform->id)
            ->delay(now()->addSeconds(5));
    }
}
