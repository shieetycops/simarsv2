<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/Dashboard.php';

final class UserStatsTest extends TestCase
{
    public function testUserStatsTable(): void
    {
        $users = [
            ['name' => 'A', 'statuses' => ['SELESAI', 'SELESAI', 'PENDING']],
            ['name' => 'B', 'statuses' => ['PROSES', 'PENDING']],
            ['name' => 'C', 'statuses' => []],            // tanpa disposisi -> disaring
            ['name' => 'D', 'statuses' => ['SELESAI']],
        ];
        $expected = [
            ['name' => 'A', 'completed' => 2, 'pending' => 1],
            ['name' => 'B', 'completed' => 0, 'pending' => 2],
            ['name' => 'D', 'completed' => 1, 'pending' => 0],
        ];
        $this->assertSame($expected, Dashboard::userStats($users));
    }
}
