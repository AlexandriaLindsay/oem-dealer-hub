<?php

namespace App\Tenancy;

use App\Models\DealerLink;
use App\Models\Manufacturer;
use App\Models\Membership;

/**
 * The manufacturer the current request (or job, or command) is working in.
 *
 * Set by the ResolveTenant middleware from the URL (/api/m/{slug}/...), or
 * explicitly with Tenant::run() in queued jobs and scheduled commands - never
 * from "whoever is signed in", so background work can't drift into the wrong
 * manufacturer.
 *
 * Bound as a scoped singleton (AppServiceProvider), so it starts empty for
 * every request and job.
 */
class Tenant
{
    private ?Manufacturer $manufacturer = null;

    /** The signed-in user's membership this request acts under: the manufacturer's, or their dealer's. */
    private ?Membership $membership = null;

    /** When acting as a dealer: that dealer's link with this manufacturer. */
    private ?DealerLink $dealerLink = null;

    public static function current(): self
    {
        return app(self::class);
    }

    public function set(Manufacturer $manufacturer, ?Membership $membership = null, ?DealerLink $dealerLink = null): void
    {
        $this->manufacturer = $manufacturer;
        $this->membership = $membership;
        $this->dealerLink = $dealerLink;
    }

    public function forget(): void
    {
        $this->manufacturer = $this->membership = $this->dealerLink = null;
    }

    public function check(): bool
    {
        return $this->manufacturer !== null;
    }

    /** The manufacturer - throws when none is set, so tenant data is never read unscoped by accident. */
    public function manufacturer(): Manufacturer
    {
        return $this->manufacturer ?? throw new MissingTenantException;
    }

    public function id(): int
    {
        return $this->manufacturer()->id;
    }

    public function membership(): ?Membership
    {
        return $this->membership;
    }

    public function dealerLink(): ?DealerLink
    {
        return $this->dealerLink;
    }

    public function isDealer(): bool
    {
        return $this->dealerLink !== null;
    }

    /**
     * Run $callback as $manufacturer (jobs, commands, seeders), then put back
     * whatever was set before.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(Manufacturer $manufacturer, callable $callback): mixed
    {
        $tenant = self::current();
        $previous = [$tenant->manufacturer, $tenant->membership, $tenant->dealerLink];
        $tenant->set($manufacturer);

        try {
            return $callback();
        } finally {
            [$tenant->manufacturer, $tenant->membership, $tenant->dealerLink] = $previous;
        }
    }
}
