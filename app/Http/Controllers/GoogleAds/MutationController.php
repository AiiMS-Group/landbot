<?php

namespace App\Http\Controllers\GoogleAds;

use App\Jobs\MutateCampaignBudget;
use Illuminate\Http\Request;

class MutationController extends BaseController
{
    /**
     * Fetch campaigns from Google Ads account
     *
     * @param Array $accountIds
     * @return \Illuminate\Support\Collection
     */
    public function fetchCampaigns($accountIds)
    {
        $serviceClient = $this->adsClient()->getGoogleAdsServiceClient();

        $query = 'SELECT campaign.id, campaign.name, campaign_budget.amount_micros, campaign_budget.id, campaign.advertising_channel_type FROM campaign';

        $campaigns = collect();

        foreach ($accountIds as $accountId) {
            $stream = $serviceClient->search($accountId, $query);
            foreach ($stream->iterateAllElements() as $row) {

                if (!$this->passFilter($row)) continue;

                $campaignId = $row->getCampaign()->getIdUnwrapped();
                $name = $row->getCampaign()->getName()->getValue();
                $budget = $row->getCampaignBudget()->getAmountMicrosUnwrapped();
                $budgetId = $row->getCampaignBudget()->getIdUnwrapped();

                $campaigns->push([
                    'name' => $name,
                    'budget' => $budget / 1000000,
                    'account_id' => $accountId,
                    'budget_id' => $budgetId,
                    'campaign_id' => $campaignId,
                ]);
            }
        }

        return $campaigns;
    }

    /**
     * Fetch campaigns with a budget higher than 1
     *
     * @param string $account
     * @return \Illuminate\Support\Collection
     */
    public function fetchActiveCampaigns($account)
    {
        $id = $this->parseAdWordsIds($account);

        return $this->fetchCampaigns($id)->filter(function ($i) {
            return $i['budget'] > 1;
        });
    }

    /**
     * Gets a list of campaigns with the corresponding budgets
     *
     * Budgets that have a value of 1 are considered paused and thus are not displayed
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function activeCampaigns(Request $request)
    {
        $account = $this->fetchAccount($request->phone)['sales_account'];

        if (!$this->accountIsValid($account))
            abort(403, 'This feature is not enabled on your account');

        $campaigns = $this->fetchActiveCampaigns($account);

        $res = [
            'name' => $account['name'],
            'current_budget' => priceFormat($campaigns->sum('budget')),
            'campaigns' => $this->formatCampaigns($campaigns),
        ];

        return $this->sendResponse('', $res);
    }

    /**
     * Mutate campaign budgets and revert after delay
     *
     * @param array $campaign
     * @param int $budget_new
     * @param \Carbon\Carbon $delay
     * @return void
     */
    public function mutateCampaign($campaign, $budget_new, $delay)
    {
        $budget_old = $campaign['budget'];

        MutateCampaignBudget::dispatch($campaign['account_id'], $campaign['budget_id'], $budget_new);
        MutateCampaignBudget::dispatch($campaign['account_id'], $campaign['budget_id'], $budget_old)
            ->delay($delay);
    }

    /**
     * Check if account has feature enabled
     *
     * @param array $account
     * @return bool
     */
    public function accountIsValid($account)
    {
        return filter_var($account['custom_field']['cf_budget_recommendation'], FILTER_VALIDATE_BOOLEAN);
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

    /**
     * Format campaign list
     *
     * Groups campaign list by budget id
     * Forms a string to display in LandBot
     *
     * @param \Illuminate\Support\Collection $campaigns
     * @return \Illuminate\Support\Collection
     */
    public function formatCampaigns($campaigns)
    {
        $res = collect();

        foreach ($campaigns->groupBy('budget_id') as $id => $items) {
            $item = $items[0];
            $val = [
                'account_id' => $item['account_id'],
                'budget_id' => $id,
                'budget' => $item['budget'],
                'items' => $items
            ];

            if ($items->count() > 1) {
                $val['string'] = $items->pluck('name')->map(function ($i) {
                    return "($i)";
                })->join('') . ' $' . $item['budget'];
            } else {
                $val['string'] = $item['name'] . ' $' . $item['budget'];
            }

            $res->push($val);
        }

        return $res;
    }
}
