<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Access\Gate;
use Shipfastlabs\Grant\Ability;
use Shipfastlabs\Grant\Grant;

final class ListCommand extends Command
{
    protected $signature = 'grant:list';

    protected $description = 'List permissions registered with Laravel Gate.';

    public function handle(Gate $gate, Grant $grant): int
    {
        $rows = collect($grant->permissionClass()::cases())
            ->filter(static fn (Ability $permission): bool => $gate->has($permission))
            ->map(static fn (Ability $permission): array => [$permission->name, $permission->value]);

        $this->table(['Permission', 'Ability'], $rows);

        return self::SUCCESS;
    }
}
