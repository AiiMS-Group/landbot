<?php

namespace App\Http\Controllers;

use App\Library\GoogleAds\GoogleAds;
use Google\Ads\GoogleAds\Util\FieldMasks;
use Google\Ads\GoogleAds\Util\V3\ResourceNames;
use Google\Ads\GoogleAds\V3\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V3\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V3\Resources\Campaign;
use Google\Ads\GoogleAds\V3\Services\CampaignOperation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoogleAdsController extends Controller
{
    private $adsClient;

    public function __construct()
    {
        $this->adsClient = (new GoogleAds())->client();
    }

    /**
     * Get current spending
     *
     * @param Request $request
     * @return void
     */
    public function spending(Request $request)
    {
        $account = $this->fetchAccount($request->phone);

        $ids = $this->parseAdWordsIds($account);

        $spending = $this->fetchSpending($ids, $request->date);

        $res = [
            'name' => $account['name'],
            'spending' => '$' . number_format($spending->sum(), 2),
        ];

        return $this->sendResponse('Success!', $res);
    }

    /**
     * Enable all ads associated
     *
     * @param Request $request
     * @return void
     */
    public function enable(Request $request)
    {
        $account = $this->fetchAccount($request->phone);

        $ids = $this->parseAdWordsIds($account);

        $this->updateAds($ids, 2);

        $res = [
            'name' => $account['name']
        ];

        return $this->sendResponse('Success!', $res);
    }

    /**
     * Pause all ads associated
     *
     * @param Request $request
     * @return void
     */
    public function pause(Request $request)
    {
        $account = $this->fetchAccount($request->phone);

        $ids = $this->parseAdWordsIds($account);

        $this->updateAds($ids, 2);

        $res = [
            'name' => $account['name']
        ];

        return $this->sendResponse('Success!', $res);
    }

    public function currentBudget(Request $request)
    {

    }

    /**
     * Fetch spending of from AdWord IDs
     *
     * @param Array $accountIds
     * @param Integer $date
     * @return \Illuminate\Support\Collection
     */
    public function fetchSpending(Array $accountIds, $dateIndex)
    {
        $serviceClient = $this->adsClient->getGoogleAdsServiceClient();

        $date = $this->dateMapper($dateIndex);
        $query = 'SELECT metrics.cost_micros FROM customer WHERE segments.date DURING ' . $date;

        $spending = collect([]);

        foreach ($accountIds as $id) {
            $sum = 0;
            $stream = $serviceClient->search($id, $query);
            foreach ($stream->iterateAllElements() as $row) {
                $sum += $row->getMetrics()->getCostMicrosUnwrapped();
            }
            $spending->push($sum);
        }

        return $spending->map(function ($item) {
            return $item / 1000000;
        });
    }

    /**
     * Update status of all campaigns in accounts
     *
     * TODO: Find way to mutate video ads
     * TODO: Find way to retrieve smart campaigns
     * @param Array $accountIds
     * @param integer $status
     * @return void
     */
    public function updateAds(Array $accountIds, $status = 1)
    {
        $serviceClient = $this->adsClient->getGoogleAdsServiceClient();
        $query = "SELECT campaign.id, campaign.advertising_channel_type FROM campaign";

        $campaignService = $this->adsClient->getCampaignServiceClient();

        foreach ($accountIds as $id) {
            $stream = $serviceClient->search($id, $query);
            $operations = collect([]);
            foreach ($stream->iterateAllElements() as $row) {
                if(!$this->passFilter($row)) continue;

                $cID = $row->getCampaign()->getIdUnwrapped();

                $c = new Campaign();
                $c->setResourceName(ResourceNames::forCampaign($id, $cID));
                $c->setStatus($this->campaignStatusMapper($status));

                $cOp = new CampaignOperation();
                $cOp->setUpdate($c);
                $cOp->setUpdateMask(FieldMasks::allSetFieldsOf($c));

                $operations->push($cOp);
            }
            $campaignService->mutateCampaigns($id, $operations->toArray());
        }
        $campaignService->close();
    }

    public function fetchBudgets(Array $accountIds)
    {
        $serviceClient = $this->adsClient->getGoogleAdsServiceClient();

        $query = 'SELECT campaign_budget.amount_micros FROM campaign';

        $budgets = collect([]);

        foreach ($accountIds as $id) {
            $sum = 0;
            $stream = $serviceClient->search($id, $query);
            foreach ($stream->iterateAllElements() as $row) {
                $sum += $row->getMetrics()->getCostMicrosUnwrapped();
            }
            $budgets->push($sum);
        }

        return $budgets->map(function ($item) {
            return $item / 1000000;
        });
    }

    /**
     * Map date index
     *
     * @param Integer $index
     * @return String
     */
    public function dateMapper($index)
    {
        switch ($index) {
            case 1:
                return 'TODAY';
            case 2:
                return 'YESTERDAY';
            case 3:
                return 'THIS_WEEK_SUN_TODAY';
            case 4:
                return 'LAST_WEEK_SUN_SAT';
            case 5:
                return 'THIS_MONTH';
            case 6:
                return 'LAST_MONTH';
            default:
                return 'TODAY';
        }
    }

    /**
     * Map status by index
     *
     * @param Integer $index
     * @return String
     */
    public function campaignStatusMapper($index)
    {
        switch ($index) {
            case 1:
                return CampaignStatus::ENABLED;
            case 2:
                return CampaignStatus::PAUSED;
            default:
                return CampaignStatus::PAUSED;
        }
    }

    /**
     * Parse AdWords IDs from FreshSales accounts
     *
     * @param \Illuminate\Support\Collection $account
     * @return array
     */
    public function parseAdWordsIds($account)
    {
        return explode("\n", str_replace('-', '', $account['custom_field']['cf_adwords_ids']));
    }

    /**
     * Filter campaigns based on blacklist
     *
     * @param mixed $row
     * @return boolean
     */
    public function passFilter($row)
    {
        /**
         * Campaign Type Enum
         *
         * 0: UNSPECIFIED
         * 1: UNKNOWN
         * 2: SEARCH
         * 3: DISPLAY
         * 4: SHOPPING
         * 5: HOTEL
         * 6: VIDEO
         */
        $blackListCampaignTypes = collect([6]);
        $campaignType = $row->getCampaign()->getAdvertisingChannelType();
        if ($blackListCampaignTypes->contains($campaignType)) {
            return false;
        }
        return true;
    }
}
