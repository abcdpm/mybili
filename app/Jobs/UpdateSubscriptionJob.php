<?php
namespace App\Jobs;

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateSubscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $subscription;
    protected $pullAll;

    public $tries = 3;
    public $timeout = 600;
    public $backoff = [60, 120];

    public function __construct(Subscription $subscription, bool $pullAll = false)
    {
        $this->subscription = $subscription;
        $this->pullAll      = $pullAll;
        $this->onQueue('default');
    }

    public function handle(): void
    {
        app(SubscriptionService::class)->updateSubscription($this->subscription, $this->pullAll);
    }

    public function displayName(): string
    {
        return 'Update Subscription Job - ' . ($this->subscription->name ?? $this->subscription->id);
    }
}