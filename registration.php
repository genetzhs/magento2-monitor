<?php
/**
 * MageSentinel_Monitor — read-only diagnostics for MageSentinel checkout monitoring.
 *
 * Copyright (c) MageSentinel. Distributed under the MIT licence.
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'MageSentinel_Monitor', __DIR__);
