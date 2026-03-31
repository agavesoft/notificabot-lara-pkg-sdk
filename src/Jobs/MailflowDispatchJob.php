<?php

namespace Agavesoft\Mailflow\Jobs;

use Agavesoft\Mailflow\MailflowClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MailflowDispatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        private string $method,
        private string $userId,
        private array $payload,
    ) {}

    public function handle(): void
    {
        $client = new MailflowClient();

        match ($this->method) {
            'identify' => $client->identify($this->userId, $this->payload),
            'track'    => $client->track($this->userId, $this->payload['event'], $this->payload['properties'] ?? []),
            'send'     => $client->send($this->userId, $this->payload['template'], $this->payload['data'] ?? []),
        };
    }
}
