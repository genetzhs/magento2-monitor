<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Api;

interface ProofInterface
{
    /**
     * The one-off challenge of a free test the admin just requested, or an
     * empty string. MageSentinel reads it back to confirm the request came
     * from this store.
     *
     * @return string
     */
    public function get(): string;
}
