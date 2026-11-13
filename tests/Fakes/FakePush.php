<?php

namespace Tests\Fakes;

use App\Models\User;
use App\Support\Push;

class FakePush extends Push
{
    /** @var list<array{user: int, title: string, body: string, url: string, tag: ?string}> */
    public array $sent = [];

    public function send(User $user, string $title, string $body, string $url = '/', ?string $tag = null): int
    {
        $this->sent[] = ['user' => $user->id, 'title' => $title, 'body' => $body, 'url' => $url, 'tag' => $tag];

        return 1;
    }
}
