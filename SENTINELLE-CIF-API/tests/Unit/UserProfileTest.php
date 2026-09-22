<?php

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\TestCase;

class UserProfileTest extends TestCase
{
    public function test_full_name_is_built_from_the_nominative_profile(): void
    {
        $user = new User([
            'username' => 'analyste.demo',
            'first_name' => 'Hawa',
            'last_name' => 'Thiama',
        ]);

        $this->assertSame('Hawa Thiama', $user->full_name);
    }

    public function test_username_is_the_fallback_when_profile_is_empty(): void
    {
        $user = new User(['username' => 'agent.demo']);

        $this->assertSame('agent.demo', $user->full_name);
    }
}
