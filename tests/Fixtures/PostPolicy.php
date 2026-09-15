<?php

declare(strict_types=1);

namespace Shipfastlabs\Grant\Tests\Fixtures;

use Shipfastlabs\Grant\Requires;

class PostPolicy
{
    #[Requires(Permission::EditPosts)]
    public function update(User $user, Post $post): bool
    {
        return $post->getAttribute('author_id') === $user->getKey();
    }

    #[Requires(Permission::ViewReports)]
    public function viewReports(User $user, Post $post): bool
    {
        return true;
    }
}
