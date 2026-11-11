<?php

namespace App\Tenancy;

use RuntimeException;

/**
 * Tenant-owned data was touched with no manufacturer set. Always a bug: wrap
 * background work in Tenant::run(), or use withoutTenant() on purpose (platform
 * staff tools only).
 */
class MissingTenantException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No manufacturer is set for this request - tenant data cannot be read or written.');
    }
}
