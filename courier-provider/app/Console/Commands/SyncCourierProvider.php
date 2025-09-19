<?php

namespace App\Console\Commands;

use App\Services\PathaoCourierProviderService;
use Illuminate\Console\Command;

class SyncCourierProvider extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'courier:sync {--provider= : Courier provider name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync courier provider data from external API';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $provider = strtolower($this->option('provider') ?? 'pathao');
        $this->info("Starting courier data sync for provider: {$provider}...");

        // Map provider name to service class
        $providerService = match ($provider) {
            'pathao' => app()->make(PathaoCourierProviderService::class),
            //'redx'  => app()->make(RadexCourierProviderService::class),
            default  => null,
        };

        if (!$providerService) {
            $this->error("Provider '{$provider}' is not supported.");
            return self::FAILURE;
        }

        /** @var PathaoCourierProviderService $providerService */
        $result = $providerService->storeCourierProviderData();

        if ($result['success']) {
            $this->info($result['message']);
            $this->info("Processed {$result['processed_count']} records");
            return self::SUCCESS;
        }

        $this->error($result['message']);
        return self::FAILURE;
    }
}
