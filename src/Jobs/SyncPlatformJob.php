<?php

namespace Moox\Sync\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Moox\Core\Traits\LogLevel;
use Moox\Sync\Models\Platform;

class SyncPlatformJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, LogLevel, Queueable, SerializesModels;

    public function handle()
    {
        $this->logDebug('SyncPlatformJob handle method entered');

        $platforms = Platform::all();

        foreach ($platforms as $sourcePlatform) {
            $this->syncPlatform($sourcePlatform);
        }

        $this->logDebug('SyncPlatformJob handle method finished');
    }

    protected function syncPlatform(Platform $sourcePlatform)
    {
        $targetPlatforms = Platform::where('id', '!=', $sourcePlatform->id)->get();

        foreach ($targetPlatforms as $targetPlatform) {
            try {
                $this->logDebug('Syncing platform', [
                    'source' => $sourcePlatform->name,
                    'target' => $targetPlatform->name,
                ]);

                $this->sendWebhook($sourcePlatform, $targetPlatform);

            } catch (\Exception $e) {
                $this->logDebug('Error syncing platform', [
                    'source' => $sourcePlatform->id,
                    'target' => $targetPlatform->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function sendWebhook(Platform $sourcePlatform, Platform $targetPlatform)
    {
        $webhookUrl = 'https://'.$targetPlatform->domain.'/sync-webhook';

        $data = [
            'event_type' => 'updated',
            'model_class' => Platform::class,
            'model' => $sourcePlatform->toArray(),
            'platform' => $sourcePlatform->toArray(),
        ];

        $response = Http::withToken($targetPlatform->api_token)
            ->post($webhookUrl, $data);

        if ($response->successful()) {
            $this->logDebug('Webhook sent successfully', [
                'source' => $sourcePlatform->id,
                'target' => $targetPlatform->id,
            ]);
        } else {
            $this->logDebug('Webhook failed', [
                'source' => $sourcePlatform->id,
                'target' => $targetPlatform->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}
