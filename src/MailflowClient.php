<?php

namespace Agavesoft\Mailflow;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MailflowClient
{
    protected string $apiUrl;
    protected string $apiToken;

    public function __construct(?string $apiUrl = null, ?string $apiToken = null)
    {
        $this->apiUrl   = rtrim($apiUrl ?? config('mailflow.api_url', ''), '/');
        $this->apiToken = $apiToken ?? config('mailflow.api_token', '');
    }

    /**
     * Identify (create or update) a contact.
     *
     * @param string $userId  Your internal user/contact identifier
     * @param array  $attrs   Attributes to merge (must include 'email' for new contacts)
     * @param bool   $async   If true, dispatch via queue
     */
    public function identify(string $userId, array $attrs = [], bool $async = false): ?array
    {
        if ($async) {
            $this->dispatchJob('identify', $userId, $attrs);
            return null;
        }

        return $this->request('POST', '/api/identify', [
            'user_id'    => $userId,
            'attributes' => $attrs,
        ]);
    }

    /**
     * Track a custom event for a contact.
     * Returns the event data including 'id' which can be used with eventStatus().
     *
     * @param string $userId  Your internal user/contact identifier
     * @param string $event   Event name (e.g. 'purchase_completed')
     * @param array  $props   Additional properties
     * @param bool   $async   If true, dispatch via queue (event ID not available)
     */
    public function track(string $userId, string $event, array $props = [], bool $async = false): ?array
    {
        if ($async) {
            $this->dispatchJob('track', $userId, ['event' => $event, 'properties' => $props]);
            return null;
        }

        return $this->request('POST', '/api/track', [
            'user_id'    => $userId,
            'event'      => $event,
            'properties' => $props,
        ]);
    }

    /**
     * Send a transactional email to a contact using a template slug.
     * Returns ['id' => $sendId] which can be used to track delivery.
     *
     * @param string $userId        Your internal user/contact identifier
     * @param string $templateSlug  The template slug configured in Mailflow
     * @param array  $data          Additional data passed to the template
     * @param bool   $async         If true, dispatch via queue
     */
    public function send(string $userId, string $templateSlug, array $data = [], bool $async = false): ?array
    {
        if ($async) {
            $this->dispatchJob('send', $userId, ['template' => $templateSlug, 'data' => $data]);
            return null;
        }

        return $this->request('POST', '/api/send', [
            'user_id'  => $userId,
            'template' => $templateSlug,
            'data'     => $data,
        ]);
    }

    /**
     * Get the processing status of a tracked event.
     * Use the 'id' returned by a synchronous track() call.
     *
     * Returns event details and which workflows were triggered.
     */
    public function eventStatus(int $eventId): ?array
    {
        return $this->request('GET', "/api/events/{$eventId}", []);
    }

    protected function dispatchJob(string $method, string $userId, array $payload): void
    {
        $job = new Jobs\MailflowDispatchJob($method, $userId, $payload);

        $connection = config('mailflow.queue_connection');
        $queue      = config('mailflow.queue_name');

        if ($connection !== null) {
            $job->onConnection($connection);
        }

        if ($queue !== null) {
            $job->onQueue($queue);
        }

        dispatch($job);
    }

    protected function request(string $method, string $path, array $payload): ?array
    {
        if (empty($this->apiUrl) || empty($this->apiToken)) {
            Log::error('MailflowClient: mailflow.api_url or mailflow.api_token not configured.');
            return null;
        }

        try {
            $request = Http::withToken($this->apiToken)
                ->timeout(10)
                ->withHeaders(['Accept' => 'application/json']);

            $response = $method === 'GET'
                ? $request->get($this->apiUrl . $path)
                : $request->send($method, $this->apiUrl . $path, ['json' => $payload]);

            if ($response->serverError()) {
                Log::error('MailflowClient: Server error', [
                    'status' => $response->status(),
                    'path'   => $path,
                    'body'   => $response->body(),
                ]);
                return null;
            }

            return $response->json();
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('MailflowClient: Connection failed', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::error('MailflowClient: Unexpected error', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
