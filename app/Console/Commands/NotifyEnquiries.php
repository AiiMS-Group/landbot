<?php

namespace App\Console\Commands;

ini_set('memory_limit', '1024MB');

use App\Library\FreshSales\FreshSales;
use App\Library\GoogleAds\GoogleAds;
use App\Library\LandBot\LandBot;
use App\Library\WildJar\WildJar;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotifyEnquiries extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notify:enquiries';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Notify accounts with flag enabled on Fresh';

    /**
     * Fresh account client
     *
     * @var \App\Library\FreshSales\Helpers\Account
     */
    private $freshClient;

    /**
     * Landbot customer client
     *
     * @var \App\Library\LandBot\Helpers\Customer
     */
    private $landbotClient;

    /**
     * Google ads client
     *
     * @var \Google\Ads\GoogleAds\V3\Services\GoogleAdsServiceClient
     */
    private $adsClient;

    /**
     * Wildjar client
     *
     * @var \App\Library\WildJar\WildJar
     */
    private $wildjarClient;

    /**
     * Current time
     *
     * @var string
     */
    private $currentTime;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->freshClient = (new FreshSales)->account();
        $this->landbotClient = (new LandBot)->customer();
        $this->adsClient = (new GoogleAds())
            ->client()
            ->getGoogleAdsServiceClient();
        $this->wildjarClient = new WildJar;
        $this->currentTime = now()->format('H:i');
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // Search
        $accounts = $this->freshClient->search([
            [
                'attribute' => 'cf_whatsapp_hourly_updates',
                'operator' => 'is_any',
                'value' => ['true']
            ]
        ])['sales_accounts'];

        // Process
        $accounts->each(function($acc) {
            // Get full fresh account
            [
                'id' => $freshId,
            ] = $acc;
            [
                'sales_account' => [
                    'custom_field' => [
                        'cf_wa_number' => $freshWaNumber,
                        'cf_adwords_ids' => $freshAdwordsIds,
                        'cf_wildjar_id' => $freshWildjarId,
                    ]
                ]
            ] = $this->freshClient->get($freshId);
            $freshWaNumber = preg_replace('/[^0-9\-]/', '', $freshWaNumber);

            // Check if customer is opted in
            $customers = $this->landbotClient->searchBy('phone', $freshWaNumber)['customers'];
            $customer = $customers->firstWhere('opt_in', true);
            if (!$customer) return;

            [
                'id' => $landbotId,
                'name' => $name,
            ] = $customer;

            // Retrieve spending and calls
            $spending = $this->fetchSpending($freshAdwordsIds);
            $calls = $this->fetchCalls($freshWildjarId);

            // Calculate cost per enquiry
            $cpe = $spending / $calls;

            // Send template to customer
            $templateParams = [
                $name,
                $this->currentTime,
                currencyFormat($spending),
                (string) $calls,
                currencyFormat($cpe),
            ];

            $res = $this->landbotClient->sendTemplate($landbotId, [
                'template_id' => 1060, //! Change template id if neccessary
                'template_params' => $templateParams,
                'template_language' => 'en',
            ]);

            if (isset($res['errors'])) {
                Log::error('Could not send template to customer', [
                    'id' => $landbotId,
                    'details' => $templateParams,
                    'response' => $res->toArray(),
                ]);
            }
        });
    }

    /**
     *
     * @param string $adwordsIds
     * @return int
     */
    private function fetchSpending($adwordsIds)
    {
        $ids = $this->parseAdWordsIds($adwordsIds);

        $date = 'TODAY';
        $query = 'SELECT metrics.cost_micros FROM customer WHERE segments.date DURING ' . $date;

        $result = collect();

        foreach ($ids as $id) {
            $spend = 0;
            $stream = $this->adsClient->search($id, $query);
            foreach ($stream->iterateAllElements() as $row) {
                $spend += $row->getMetrics()
                    ->getCostMicrosUnwrapped();
            }
            $result->push($spend / 1000000);
        }

        return $result->sum();
    }

    /**
     * Fetch number of calls
     *
     * @param string $wildjarId
     * @return int
     */
    private function fetchCalls($wildjarId)
    {
        $ids = $this->parseWildJarId($wildjarId);

        $data = [
            'account' => $ids->join(','),
            'datefrom' => today()->startOfDay(),
            'dateto' => today()->endOfDay(),
            'timezone' => 'Australia/Sydney',
        ];

        [
            'summary' => [
                'answeredTot' => $answered,
                'missedTot' => $missed,
                'abandonedTot' => $abandoned,
            ]
        ] = $this->wildjarClient->summary()->filter($data);

        return $answered + $missed + $abandoned;
    }

    /**
     * Parse adwords ids
     *
     * @param string $adwordsIds
     * @return Collection
     */
    private function parseAdWordsIds($adwordsIds)
    {
        return Str::of($adwordsIds)->replace('-', '')->explode("\n");
    }

    /**
     * Parse wildjar id
     *
     * @param string $wildjarId
     * @return Collection
     */
    private function parseWildJarId($wildjarId)
    {
        $allAccounts = $this->wildjarClient->account()->all();

        $allAccountIds = $allAccounts->filter(function ($q) use ($wildjarId) {
            return $q['father'] == $wildjarId;
        })->pluck('id');

        return $allAccountIds->push($wildjarId);
    }
}
