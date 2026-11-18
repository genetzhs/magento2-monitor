<?php
declare(strict_types=1);

namespace MageSentinel\Monitor\Model;

use MageSentinel\Monitor\Api\ProofInterface;

/**
 * Anonymous by design: it shows only a random code the admin's own click put
 * there, for two minutes at most, and nothing else about the store.
 */
class Proof implements ProofInterface
{
    /** @var State */
    private $state;

    /**
     * Constructor.
     *
     * @param State $state
     */
    public function __construct(State $state)
    {
        $this->state = $state;
    }

    /**
     * The free-test challenge, for MageSentinel to confirm the request came from this store.
     *
     * @return string
     */
    public function get(): string
    {
        return $this->state->proof();
    }
}
