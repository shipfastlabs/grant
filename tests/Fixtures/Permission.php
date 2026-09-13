<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Tests\Fixtures;

use Shipfastlabs\Grant\Ability;

enum Permission: string implements Ability
{
    case EditPosts = 'edit-posts';
    case DeletePosts = 'delete-posts';
    case ViewReports = 'view-reports';

    public function deniedMessage(): string
    {
        return match ($this) {
            self::EditPosts => 'Editor access is required.',
            default => 'Not allowed.',
        };
    }
}
