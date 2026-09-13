<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

use Shipfastlabs\Grant\Ability;

enum Permission: string implements Ability
{
    case EditPosts = 'edit-posts';
    case ViewReports = 'view-reports';

    public function deniedMessage(): string
    {
        return 'You are not allowed to do that.';
    }
}
