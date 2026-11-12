<?php

namespace App\Integrations;

use App\Jobs\DeliverWebhook;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Plan;
use App\Tenancy\Tenant;
use Illuminate\Support\Str;

/**
 * Telling a manufacturer's own systems that something happened. Call
 * Webhooks::send() inside the current manufacturer; it records a delivery
 * for each of their webhooks that wants the event, and queues the calls to
 * go out once the surrounding transaction commits - so nothing is sent for
 * a change that was rolled back.
 *
 * Every call is a POST of JSON, signed (see sign()), with a unique id so the
 * receiver can ignore a repeat.
 */
class Webhooks
{
    /** Set while the ERP connection is making changes (asErp), so the calls say "source": "erp". */
    private static ?string $source = null;

    /**
     * Runs $callback as a change that came from their ERP through the ERP
     * connection: webhooks it fires carry "source": "erp", so their ERP can
     * ignore the echo of what it just told us.
     */
    public static function asErp(callable $callback): mixed
    {
        $previous = self::$source;
        self::$source = 'erp';
        try {
            return $callback();
        } finally {
            self::$source = $previous;
        }
    }

    public static function send(string $event, array $data): void
    {
        // Kept, but silent, on a plan without ERP integration - they come back on an upgrade.
        if (! Plan::includes(Tenant::current()->manufacturer(), 'erp_integration')) {
            return;
        }

        foreach (Webhook::where('active', true)->get() as $webhook) {
            if ($webhook->wants($event)) {
                self::deliver($webhook, $event, $data);
            }
        }
    }

    /** One call to one webhook - also the "Send a test" button, whatever events it's set to. */
    public static function deliver(Webhook $webhook, string $event, array $data): WebhookDelivery
    {
        $delivery = new WebhookDelivery;
        $uuid = (string) Str::uuid();
        $delivery->forceFill([
            'webhook_id' => $webhook->id,
            'uuid' => $uuid,
            'event' => $event,
            'payload' => [
                'id' => $uuid,
                'event' => $event,
                'created_at' => now()->toIso8601String(),
                'manufacturer' => Tenant::current()->manufacturer()->slug,
                // "api" when the change came in through an API key, "erp" when the ERP connection fetched it -
                // so their ERP can ignore the echo of its own update.
                'source' => self::$source ?? (request()->attributes->has('api_key') ? 'api' : 'app'),
                'data' => $data,
            ],
            'status' => WebhookDelivery::PENDING,
        ])->save();

        DeliverWebhook::dispatch(Tenant::current()->id(), $delivery->id)->afterCommit();

        return $delivery;
    }

    /**
     * The signature header: "t=<unix time>,v1=<hex HMAC-SHA256 of '<t>.<body>' with the secret>".
     * The time is in it so a captured call can't be replayed later.
     */
    public static function sign(string $body, string $secret, int $time): string
    {
        return 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$body, $secret);
    }
}
