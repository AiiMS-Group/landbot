<?php

namespace App\Http\Controllers\GoogleAds;

use App\Jobs\MutateCampaignBudget;
use App\Models\Client;
use App\Models\StatusMutation;
use Carbon\Carbon;
use Google\Ads\GoogleAds\Util\FieldMasks;
use Google\Ads\GoogleAds\Util\V3\ResourceNames;
use Google\Ads\GoogleAds\V3\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V3\Resources\Campaign;
use Google\Ads\GoogleAds\V3\Services\CampaignOperation;
use Illuminate\Http\Request;

class StatusController extends MutationController
{
    /**
     * Enable all ads associated
     *
     * @param Request $request
     * @return void
     */
    public function enable(Request $request)
    {
        $account = $this->fetchAccount($request->phone)['sales_account'];

        $ids = $this->parseAdWordsIds($account);

        $this->updateAds($ids, 1);

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
        $account = $this->fetchAccount($request->phone)['sales_account'];

        $id = $this->parseAdWordsIds($account)[0];

        $campaigns = $this->fetchCampaigns($id)->filter(function ($i) {
            return $i['budget'] > 1;
        });
        $campaigns = $this->formatCampaigns($campaigns);

        $budget_new = 1;
        $delay = $this->durationMapper($request->duration);

        if ($request->campaign - 1 < count($campaigns)) {
            $campaign = $campaigns[$request->campaign - 1];
            $this->storeMutation($account, $delay, $campaign, true);
            $this->mutateCampaign($id, $campaign, $budget_new, $delay);
        } else {
            foreach ($campaigns as $campaign) {
                $this->storeMutation($account, $delay, $campaign, true);
                $this->mutateCampaign($id, $campaign, $budget_new, $delay);
            }
        }

        return $this->sendResponse('', [
            'reverted' => $delay->format("l M d, Y h:ia"),
        ]);
    }

    /**
     * Update status of all campaigns in accounts
     *
     * TODO: Find way to mutate video ads
     * TODO: Find way to retrieve smart campaigns
     * @param Array|Collection $accountIds
     * @param integer $status
     * @return void
     */
    public function updateAds($accountIds, $status = 1)
    {
        $serviceClient = $this->adsClient()->getGoogleAdsServiceClient();
        $query = "SELECT campaign.id, campaign.advertising_channel_type FROM campaign";

        $campaignService = $this->adsClient()->getCampaignServiceClient();

        foreach ($accountIds as $id) {
            $stream = $serviceClient->search($id, $query);
            $operations = collect([]);
            foreach ($stream->iterateAllElements() as $row) {
                if (!$this->passFilter($row)) continue;

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
     * Returns a DateTime when the change will end
     *
     * Options:
     * 1. Today
     * 2. Today and Tomorrow
     * 3. Next 3 Days
     * 4. Next 7 Days
     *
     * @param int $index
     * @return \Carbon\Carbon
     */
    public function durationMapper($index)
    {
        $date = Carbon::today();
        switch ($index) {
            case 1:
                $date->addDays(1);
                break;
            case 2:
                $date->addDays(2);
                break;
            case 3:
                $date->addDays(3);
                break;
            case 4:
                $date->addDays(7);
                break;
            default:
                $date->addDay();
                break;
        }

        return $date->setTime(9, 0);
    }

    private function storeMutation($account, $revert, $campaign, $pause = true)
    {
        $status = StatusMutation::make([
            'status_old'  => $pause ? 'Active' : 'Paused',
            'status_new'  => $pause ? 'Paused' : 'Active',
            'campaign'    => explode(' $', $campaign['string'])[0],
            'date_revert' => $revert,
        ]);
        $status->client()->associate(
            Client::firstWhere('freshsales_id', $account)
        );
        $status->save();
    }
}
