<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Api;

interface DiagnosticsInterface
{
    /**
     * Read-only store diagnostics for MageSentinel.
     *
     * @param string|null $sku SKU the monitor is checking out, for stock truth.
     * @return mixed[]
     */
    public function get($sku = null): array;
}
