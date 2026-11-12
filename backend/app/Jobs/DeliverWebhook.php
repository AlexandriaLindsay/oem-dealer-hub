<?php

namespace App\Jobs;

use App\Integrations\UrlGuard;
use App\Integrations\Webhooks;
use App\Models\Manufacturer;
use App\Models\WebhookDelivery;
use App\Tenancy\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

/**
 * Makes one webhook call, inside its manufacturer (Tenant::run). A 2xx reply
 * is delivered; anything else, or no reply in 10 seconds, is tried again -
 * after 1 minute, 5, 30, 2 hours and 6 hours - then marked failed. The
 * manufacturer can retry a failed one from Settings.
 *
 * Carries only ids (not the payload or secret), so nothing sensitive sits in
 * the queue table.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = WebhookDelivery::MAX_ATTEMPTS;

    public function __construct(public int $manufacturerId, public int $deliveryId) {}

    /** @return list<int> seconds to wait before each retry */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 21600];
    }

    public function handle(UrlGuard $guard): void
    {
        $manufacturer = Manufacturer::find($this->manufacturerId);
        if (! $manufacturer) {
            return;
        }

        Tenant::run($manufacturer, function () use ($guard) {
            $delivery = WebhookDelivery::with('webhook')->find($this->deliveryId);
            if (! $delivery || $delivery->status === WebhookDelivery::DELIVERED || ! $delivery->webhook) {
                return;
            }

            $delivery->increment('attempts');
            $outcome = $this->call($delivery, $guard);

            if ($outcome === true) {
                $delivery->forceFill(['status' => WebhookDelivery::DELIVERED, 'delivered_at' => now(), 'error' => null])->save();

                return;
            }

            $delivery->forceFill(['error' => mb_substr($outcome, 0, 500)])->save();
            // Not allowed to call it at all: no point retrying.
            if (str_starts_with($outcome, 'Address not allowed') || $this->attempts() >= $this->tries) {
                $delivery->forceFill(['status' => WebhookDelivery::FAILED])->save();

                return;
            }
            $this->release($this->backoff()[$this->attempts() - 1] ?? 21600);
        });
    }

    /** True when delivered; otherwise what went wrong, in words. */
    private function call(WebhookDelivery $delivery, UrlGuard $guard): true|string
    {
        $webhook = $delivery->webhook;
        if ($problem = $guard->problem($webhook->url)) {
            return 'Address not allowed: '.$problem;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                // A redirect could lead somewhere the guard never checked.
                ->withoutRedirecting()
                ->withHeaders([
                    'User-Agent' => 'OEMDealerHub-Webhooks/1',
                    'X-OEMDH-Event' => $delivery->event,
                    'X-OEMDH-Delivery' => $delivery->uuid,
                    'X-OEMDH-Signature' => Webhooks::sign($body, $webhook->secret, now()->timestamp),
                ])
                ->withBody($body, 'application/json')
                ->post($webhook->url);
        } catch (ConnectionException $e) {
            $delivery->forceFill(['response_code' => null])->save();

            return 'No answer from the address: '.$e->getMessage();
        }

        $delivery->forceFill(['response_code' => $response->status()])->save();

        return $response->successful() ? true : 'The address answered '.$response->status().'.';
    }
}
