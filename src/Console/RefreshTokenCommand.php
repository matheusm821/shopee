<?php

namespace Laraditz\Shopee\Console;

use Illuminate\Console\Command;
use Laraditz\Shopee\Models\ShopeeAccessToken;
use Throwable;

class RefreshTokenCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopee:refresh-token';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh existing access token before it expired.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $query = $this->getQuery();
        $processed = 0;
        $succeeded = 0;
        $failed = 0;

        $query->lazy()->each(function (ShopeeAccessToken $item) use (&$processed, &$succeeded, &$failed) {
            $processed++;
            $entity = optional($item->entity)->name ?: "entity #{$item->entity_id}";

            $this->info(__('<fg=yellow>Refreshing :entity access token.</>', ['entity' => $entity]));

            try {
                $refreshedToken = app('shopee')->auth()->refreshToken($item);

                if (!$refreshedToken instanceof ShopeeAccessToken) {
                    $failed++;
                    $this->error(__(
                        'Failed to refresh :entity access token: no access token was returned.',
                        ['entity' => $entity]
                    ));

                    return;
                }

                $succeeded++;
                $this->info(__(':entity access token was refreshed.', ['entity' => $entity]));
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
                $this->error(__(
                    'Failed to refresh :entity access token due to an unexpected error. See the application logs for details.',
                    ['entity' => $entity]
                ));
            }
        });

        $this->info(__(
            'Token refresh summary: :processed processed, :succeeded refreshed, :failed failed.',
            compact('processed', 'succeeded', 'failed')
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function getQuery()
    {
        $query = ShopeeAccessToken::query();

        $query->where('expires_at', '<', now()->addMinutes(25));

        return $query;
    }
}
