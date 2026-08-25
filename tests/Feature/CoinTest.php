<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Game\CoinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoinTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('game.coins.premium_tiers', [
            ['coins' => 300, 'days' => 3],
            ['coins' => 500, 'days' => 3],
        ]);

        $this->user = User::create(['telegram_id' => 4242, 'first_name' => 'Test']);
    }

    protected function coins(): CoinService
    {
        return app(CoinService::class);
    }

    public function test_awards_add_to_both_the_balance_and_the_lifetime_total(): void
    {
        $this->coins()->award($this->user, 12);
        $this->coins()->award($this->user, 8);

        $fresh = $this->user->fresh();
        $this->assertSame(20, $fresh->coins);
        $this->assertSame(20, $fresh->coins_lifetime);
    }

    public function test_crossing_a_tier_opens_premium_days(): void
    {
        $this->coins()->award($this->user, 299);
        $this->assertNull($this->user->fresh()->premium_until);

        $this->coins()->award($this->user, 1);

        $fresh = $this->user->fresh();
        $this->assertSame(1, $fresh->premium_tier);
        $this->assertTrue($fresh->premium_until->isFuture());
        $this->assertSame(3, (int) round(now()->diffInDays($fresh->premium_until, absolute: true)));
    }

    public function test_a_second_tier_extends_rather_than_restarts(): void
    {
        $this->coins()->award($this->user, 300);
        $firstEnd = $this->user->fresh()->premium_until;

        $this->coins()->award($this->user, 200);
        $fresh = $this->user->fresh();

        $this->assertSame(2, $fresh->premium_tier);
        $this->assertTrue($fresh->premium_until->gt($firstEnd), 'the second tier must add days');
    }

    public function test_a_tier_is_only_ever_collected_once(): void
    {
        $this->coins()->award($this->user, 400);
        $after = $this->user->fresh()->premium_until;

        // More coins, still short of the next tier: nothing should change.
        $this->coins()->award($this->user, 50);

        $this->assertEquals($after, $this->user->fresh()->premium_until);
        $this->assertSame(1, $this->user->fresh()->premium_tier);
    }

    public function test_spending_the_balance_never_removes_a_reward(): void
    {
        $this->coins()->award($this->user, 320);
        $this->user->update(['coins' => 0]);   // as if spent

        $summary = $this->coins()->summary($this->user->fresh());

        $this->assertSame(0, $summary['balance']);
        $this->assertSame(320, $summary['lifetime']);
        $this->assertTrue($summary['premium']['active']);
        $this->assertSame(180, $summary['premium']['remaining']);
    }
}
