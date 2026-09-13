<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Shipfastlabs\Grant\Tests\Fixtures\Post;
use Shipfastlabs\Grant\Tests\Fixtures\PostPolicy;
use Shipfastlabs\Grant\Tests\Fixtures\Role;
use Shipfastlabs\Grant\Tests\Fixtures\User;

beforeEach(function (): void {
    Gate::policy(Post::class, PostPolicy::class);
});

it('requires the attributed permission and still runs the policy', function (): void {
    $owner = User::query()->create(['name' => 'Owner']);
    $other = User::query()->create(['name' => 'Other']);
    $post = new Post(['author_id' => $owner->getKey()]);

    $owner->grant(Role::Viewer);
    expect(Gate::forUser($owner)->allows('update', $post))->toBeFalse();

    $owner->grant(Role::Editor);
    expect(Gate::forUser($owner)->allows('update', $post))->toBeTrue();

    $other->grant(Role::Editor);
    expect(Gate::forUser($other)->allows('update', $post))->toBeFalse();
});
