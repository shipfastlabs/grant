<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Shipfastlabs\Grant\Grant;
use Shipfastlabs\Grant\Role;

use function Laravel\Prompts\select;

final class SyncCommand extends Command
{
    protected $signature = 'grant:sync';

    protected $description = 'Remap or delete stored roles that no longer match a case of the role enum.';

    public function handle(Grant $grant): int
    {
        $roleClass = $grant->roleClass();
        $known = array_map(static fn (Role $role): string => $role->value, $roleClass::cases());
        $column = config('grant.storage') === 'column';
        $model = $this->storedModel($grant, $column);

        $orphans = $model::query()->whereNotNull('role')->whereNotIn('role', $known)->distinct()->pluck('role')
            ->filter(static fn (mixed $role): bool => is_string($role));

        if ($orphans->isEmpty()) {
            $this->components->info("Every stored role matches a case of {$roleClass}.");

            return self::SUCCESS;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('Stored roles without a matching enum case: '.$orphans->join(', '));

            return self::FAILURE;
        }

        foreach ($orphans as $orphan) {
            $count = $model::query()->where('role', $orphan)->count();

            $choice = (string) select(
                "Stored role [{$orphan}] no longer exists. What should its {$count} assignments become?",
                [...array_combine($known, $known), '__delete' => 'Delete them'],
            );

            if ($column) {
                $model::query()->where('role', $orphan)->update(['role' => $choice === '__delete' ? null : $choice]);
            } else {
                foreach ($model::query()->where('role', $orphan)->cursor() as $row) {
                    if ($choice === '__delete' || $this->alreadyHolds($model, $row, $choice)) {
                        $row->delete();

                        continue;
                    }

                    $row->forceFill(['role' => $choice])->save();
                }
            }

            $this->components->info($choice === '__delete'
                ? "Deleted {$count} assignments of [{$orphan}]."
                : "Mapped {$count} assignments of [{$orphan}] to [{$choice}].");
        }

        return self::SUCCESS;
    }

    /** @param class-string<Model> $model */
    private function alreadyHolds(string $model, Model $row, string $role): bool
    {
        return $model::query()
            ->where('role', $role)
            ->where('user_id', $row->getAttribute('user_id'))
            ->where('scopeable_type', $row->getAttribute('scopeable_type'))
            ->where('scopeable_id', $row->getAttribute('scopeable_id'))
            ->exists();
    }

    /** @return class-string<Model> */
    private function storedModel(Grant $grant, bool $column): string
    {
        if (! $column) {
            return $grant->assignmentModel();
        }

        $userClass = config('auth.providers.users.model');

        if (! is_string($userClass) || ! is_subclass_of($userClass, Model::class)) {
            throw new InvalidArgumentException('The auth user model could not be resolved.');
        }

        return $userClass;
    }
}
