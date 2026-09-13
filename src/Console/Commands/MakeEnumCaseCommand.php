<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Console\Commands;

use BackedEnum;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use ReflectionEnum;

abstract class MakeEnumCaseCommand extends GeneratorCommand
{
    protected string $configKey;

    public function handle(): ?bool
    {
        $enum = config("grant.{$this->configKey}");

        if (! is_string($enum) || $enum === '') {
            $this->fail("The grant.{$this->configKey} config value must be an enum class name.");
        }

        $name = $this->argument('name');
        $name = Str::studly(is_string($name) ? $name : '');

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            $this->fail("The {$this->type} name must be a valid PHP identifier.");
        }

        if (is_subclass_of($enum, BackedEnum::class)) {
            $this->addCase($enum, $name, Str::kebab($name));
        } else {
            $this->createEnum($enum, $name, Str::kebab($name));
        }

        return null;
    }

    /** @return array<string, array{string, string}> */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'name' => [
                'What should the '.strtolower($this->type).' be named?',
                $this->type === 'Role' ? 'E.g. Editor' : 'E.g. PublishPosts',
            ],
        ];
    }

    protected function getStub(): string
    {
        $stub = strtolower($this->type).'.stub';

        return $this->files->exists($custom = $this->laravel->basePath("stubs/{$stub}"))
            ? $custom
            : __DIR__.'/../../../stubs/'.$stub;
    }

    /** @param class-string<BackedEnum> $enum */
    private function addCase(string $enum, string $case, string $value): void
    {
        $reflection = new ReflectionEnum($enum);

        if ($reflection->hasCase($case) || $enum::tryFrom($value) !== null) {
            $this->fail("{$this->type} {$case} ('{$value}') already exists.");
        }

        $path = $reflection->getFileName();

        if ($path === false) {
            $this->fail("The configured {$this->type} enum file could not be read.");
        }

        $lines = explode("\n", $this->files->get($path));
        array_splice($lines, $reflection->getEndLine() - 1, 0, sprintf("    case %s = '%s';", $case, $value));
        $this->files->put($path, implode("\n", $lines));
        $this->components->info("Added {$case} to {$enum}.");
    }

    private function createEnum(string $enum, string $case, string $value): void
    {
        $path = $this->getPath($enum);
        $permission = config('grant.permissions');
        $permission = is_string($permission) ? $permission : '';

        $this->makeDirectory($path);
        $this->files->put($path, str_replace(
            ['{{ case }}', '{{ value }}', '{{ permissionClass }}', '{{ permission }}'],
            [$case, $value, $permission, class_basename($permission)],
            $this->buildClass($enum),
        ));
        $this->components->info(sprintf('%s [%s] created successfully.', $this->type, $path));
    }
}
