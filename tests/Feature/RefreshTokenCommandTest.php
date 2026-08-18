<?php

namespace Laraditz\Shopee\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Laraditz\Shopee\Models\ShopeeAccessToken;
use Laraditz\Shopee\Models\ShopeeShop;
use Laraditz\Shopee\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class RefreshTokenCommandTest extends TestCase
{
    #[Test]
    public function it_refreshes_all_eligible_tokens_and_returns_success(): void
    {
        $firstToken = $this->createToken(12345678, 'Alpha');
        $secondToken = $this->createToken(12345679, 'Beta');

        Http::fakeSequence()
            ->push($this->successfulResponse('access-alpha-new', 'refresh-alpha-new'))
            ->push($this->successfulResponse('access-beta-new', 'refresh-beta-new'));

        $this->artisan('shopee:refresh-token')
            ->expectsOutputToContain('Alpha access token was refreshed.')
            ->expectsOutputToContain('Beta access token was refreshed.')
            ->expectsOutput('Token refresh summary: 2 processed, 2 refreshed, 0 failed.')
            ->assertSuccessful();

        $this->assertSame('access-alpha-new', $firstToken->fresh()->access_token);
        $this->assertSame('access-beta-new', $secondToken->fresh()->access_token);
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_continues_after_an_exception_and_returns_failure(): void
    {
        $failedToken = $this->createToken(12345678, 'Alpha');
        $refreshedToken = $this->createToken(12345679, 'Beta');

        Http::fakeSequence()
            ->push(['error' => 'invalid_refresh_token'], 500)
            ->push($this->successfulResponse('access-beta-new', 'refresh-beta-new'));

        $this->artisan('shopee:refresh-token')
            ->expectsOutputToContain('Failed to refresh Alpha access token due to an unexpected error.')
            ->expectsOutputToContain('Beta access token was refreshed.')
            ->expectsOutput('Token refresh summary: 2 processed, 1 refreshed, 1 failed.')
            ->assertFailed();

        $this->assertSame('access-old', $failedToken->fresh()->access_token);
        $this->assertSame('access-beta-new', $refreshedToken->fresh()->access_token);
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_treats_a_response_without_an_access_token_as_a_failure_and_continues(): void
    {
        $failedToken = $this->createToken(12345678, 'Alpha');
        $refreshedToken = $this->createToken(12345679, 'Beta');

        Http::fakeSequence()
            ->push(['error' => 'invalid_refresh_token'], 200)
            ->push($this->successfulResponse('access-beta-new', 'refresh-beta-new'));

        $this->artisan('shopee:refresh-token')
            ->expectsOutput('Failed to refresh Alpha access token: no access token was returned.')
            ->doesntExpectOutput('Alpha access token was refreshed.')
            ->expectsOutputToContain('Beta access token was refreshed.')
            ->expectsOutput('Token refresh summary: 2 processed, 1 refreshed, 1 failed.')
            ->assertFailed();

        $this->assertSame('access-old', $failedToken->fresh()->access_token);
        $this->assertSame('access-beta-new', $refreshedToken->fresh()->access_token);
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_processes_every_token_when_multiple_refreshes_fail(): void
    {
        $this->createToken(12345678, 'Alpha');
        $this->createToken(12345679, 'Beta');
        $refreshedToken = $this->createToken(12345680, 'Gamma');

        Http::fakeSequence()
            ->push(['error' => 'invalid_refresh_token'], 200)
            ->push(['error' => 'server_error'], 500)
            ->push($this->successfulResponse('access-gamma-new', 'refresh-gamma-new'));

        $this->artisan('shopee:refresh-token')
            ->expectsOutputToContain('Failed to refresh Alpha access token:')
            ->expectsOutputToContain('Failed to refresh Beta access token due to an unexpected error.')
            ->expectsOutputToContain('Gamma access token was refreshed.')
            ->expectsOutput('Token refresh summary: 3 processed, 1 refreshed, 2 failed.')
            ->assertFailed();

        $this->assertSame('access-gamma-new', $refreshedToken->fresh()->access_token);
        Http::assertSentCount(3);
    }

    #[Test]
    public function it_returns_success_when_no_tokens_are_eligible(): void
    {
        $this->createToken(12345678, 'Alpha', now()->addMinutes(30));
        Http::fake();

        $this->artisan('shopee:refresh-token')
            ->expectsOutput('Token refresh summary: 0 processed, 0 refreshed, 0 failed.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    private function createToken(int $shopId, string $shopName, $expiresAt = null): ShopeeAccessToken
    {
        $shop = ShopeeShop::create([
            'id' => $shopId,
            'name' => $shopName,
        ]);

        return $shop->accessToken()->create([
            'access_token' => 'access-old',
            'refresh_token' => 'refresh-old',
            'expires_at' => $expiresAt ?? now()->addMinutes(10),
        ]);
    }

    private function successfulResponse(string $accessToken, string $refreshToken): array
    {
        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expire_in' => 14400,
        ];
    }
}
