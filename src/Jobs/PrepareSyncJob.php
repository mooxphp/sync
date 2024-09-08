<?php

namespace Moox\Sync\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Moox\Core\Traits\LogLevel;
use Moox\Sync\Models\Platform;

class PrepareSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, LogLevel, Queueable, SerializesModels;

    protected $identifierField;

    protected $identifierValue;

    protected $modelClass;

    protected $eventType;

    protected $platformId;

    protected $syncConfigurations;

    public function __construct($identifierField, $identifierValue, $modelClass, $eventType, $platformId, $syncConfigurations)
    {
        $this->identifierField = $identifierField;
        $this->identifierValue = $identifierValue;
        $this->modelClass = $modelClass;
        $this->eventType = $eventType;
        $this->platformId = $platformId;
        $this->syncConfigurations = $syncConfigurations;
    }

    public function handle()
    {
        $model = $this->findModel();
        $sourcePlatform = Platform::findOrFail($this->platformId);

        $this->logDebug('Moox Sync: PrepareSyncJob handling', [
            'identifier_field' => $this->identifierField,
            'identifier_value' => $this->identifierValue,
            'model_class' => $this->modelClass,
            'event_type' => $this->eventType,
            'platform_id' => $this->platformId,
        ]);

        $syncData = [
            'event_type' => $this->eventType,
            'model' => $model ? $this->getFullModelData($model) : [$this->identifierField => $this->identifierValue],
            'model_class' => $this->modelClass,
            'platform' => $sourcePlatform->toArray(),
        ];

        $this->logDebug('Moox Sync: Prepared sync data', [
            'sync_data' => $syncData,
        ]);

        $this->invokeWebhooks($syncData);
    }

    protected function findModel()
    {
        $this->logDebug('Moox Sync: Finding model', [
            'model_class' => $this->modelClass,
            'identifier_field' => $this->identifierField,
            'identifier_value' => $this->identifierValue,
        ]);

        $model = $this->modelClass::where($this->identifierField, $this->identifierValue)->first();

        if (! $model) {
            Log::warning('Moox Sync: Model not found', [
                'model_class' => $this->modelClass,
                'identifier_field' => $this->identifierField,
                'identifier_value' => $this->identifierValue,
            ]);
        } else {
            $this->logDebug('Moox Sync: Model found', [
                'model_class' => $this->modelClass,
                'model_data' => $model->toArray(),
            ]);
        }

        return $model;
    }

    protected function getFullModelData($model)
    {
        $this->logDebug('Moox Sync: Getting full model data', [
            'model_class' => $this->modelClass,
            'identifier_field' => $this->identifierField,
            'identifier_value' => $this->identifierValue,
        ]);

        $transformerClass = config("sync.transformer_bindings.{$this->modelClass}");

        if ($transformerClass && class_exists($transformerClass)) {
            $transformer = new $transformerClass($model);
            $transformedData = $transformer->transform();

            $this->logDebug('Moox Sync: Transformed data', [
                'transformed_data' => $transformedData,
            ]);

            return $transformedData;
        }

        return $model->toArray();
    }

    protected function invokeWebhooks(array $data)
    {
        $webhookPath = config('sync.sync_webhook_url', '/sync-webhook');

        foreach ($this->syncConfigurations as $syncConfig) {
            $targetPlatform = Platform::findOrFail($syncConfig['target_platform_id']);
            $webhookUrl = 'https://'.$targetPlatform->domain.$webhookPath;

            $this->logDebug('Moox Sync: Preparing to invoke webhook', [
                'platform' => $targetPlatform->name,
                'webhook_url' => $webhookUrl,
                'full_data' => $data,
            ]);

            try {
                $response = Http::withToken($targetPlatform->api_token)->post($webhookUrl, $data);

                $this->logDebug('Moox Sync: Webhook invoked', [
                    'platform' => $targetPlatform->name,
                    'webhook_url' => $webhookUrl,
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                ]);
            } catch (\Exception $e) {
                $this->logDebug('Moox Sync: Webhook invocation error', [
                    'platform' => $targetPlatform->name,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }
}
