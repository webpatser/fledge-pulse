<?php

namespace Tests\Fiber\Livewire\Fixtures;

use Carbon\CarbonInterval;
use Closure;
use Laravel\Pulse\Fiber\Livewire\Concerns\RemembersConcurrently;
use Laravel\Pulse\Livewire\Concerns\HasPeriod;

/**
 * A minimal card using the trait the way a Pulse Livewire card would.
 *
 * Also listed in phpstan.neon.dist so the trait, which nothing in src uses,
 * is analysed in the context of a class.
 */
final class RemembersConcurrentlyCard
{
    use HasPeriod;
    use RemembersConcurrently;

    /**
     * @param  array<array-key, Closure(CarbonInterval): mixed>  $queries
     * @return array<array-key, array{0: mixed, 1: float, 2: string}>
     */
    public function many(array $queries, Closure|int $ttl = 5): array
    {
        return $this->rememberMany($queries, $ttl);
    }
}
