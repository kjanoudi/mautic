<?php
/**
 * @package     Mautic
 * @copyright   2016 Mautic, Inc. All rights reserved
 * @author      Mautic, Inc
 * @link        https://mautic.org
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticSocialBundle\Helper;

use Mautic\AssetBundle\Helper\TokenHelper as AssetTokenHelper;
use Mautic\PageBundle\Entity\Trackable;
use Mautic\PageBundle\Helper\TokenHelper as PageTokenHelper;
use Mautic\PageBundle\Model\TrackableModel;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticSocialBundle\Model\SocialEventLogModel;

/**
 * Class CampaignEventHelper
 *
 * @package MauticPlugin\MauticSocialBundle\Helper\CampaignEventHelper
 */
class CampaignEventHelper
{
    /**
     * @var IntegrationHelper
     */
    protected $integrationHelper;

    /**
     * @var TrackableModel
     */
    protected $trackableModel;

    /**
     * @var PageTokenHelper
     */
    protected $pageTokenHelper;

    /**
     * @var AssetTokenHelper
     */
    protected $assetTokenHelper;

    /**
     * @var array
     */
    protected $clickthrough = [];

    /**
     * CampaignEventHelper constructor.
     *
     * @param IntegrationHelper $integrationHelper
     * @param TrackableModel    $trackableModel
     */
    public function __construct(
        IntegrationHelper $integrationHelper,
        TrackableModel $trackableModel,
        PageTokenHelper $pageTokenHelper,
        AssetTokenHelper $assetTokenHelper
    ) {
        $this->integrationHelper = $integrationHelper;
        $this->trackableModel    = $trackableModel;
        $this->pageTokenHelper   = $pageTokenHelper;
        $this->assetTokenHelper  = $assetTokenHelper;
    }

    /**
     * @param MauticFactory $factory
     * @param               $lead
     * @param               $event
     *
     * @return bool
     */
    public function sendTweetAction($lead, $event)
    {
        $tweetSent = false;

        /** @var \MauticPlugin\MauticSocialBundle\Integration\TwitterIntegration $twitterIntegration */
        $twitterIntegration = $this->integrationHelper->getIntegrationObject('Twitter');

        // Setup clickthrough for URLs in tweet
        $this->clickthrough = [
            'source' => ['campaign', $event['campaign']['id']]
        ];

        $tweetText = $event['properties']['tweet_text'];
        $tweetText = $this->parseTweetText($tweetText, $lead);
        $tweetUrl  = $twitterIntegration->getApiUrl('statuses/update');
        $status    = ['status' => $tweetText];

        // fire the tweet
        $sendTweet = $twitterIntegration->makeRequest($tweetUrl, $status, 'POST', ['append_callback' => false]);

        // verify the tweet was sent by checking for a tweet id
        if (is_array($sendTweet) && array_key_exists('id_str', $sendTweet)) {
            $tweetSent = true;
        }

        return ($tweetSent) ? ['text' => $tweetText, 'response' => $sendTweet] : false;
    }

    /**
     * PreParse the twitter message and replace placeholders with values.
     *
     * @param $tweet
     * @param $lead
     *
     * @return mixed
     */
    protected function parseTweetText($tweet, $lead)
    {
        /* @var \Mautic\LeadBundle\Entity\Lead $lead */
        $tweetHandle = $lead->getFieldValue('twitter');
        $tokens      = [
            '{twitter_handle}' => (strpos($tweetHandle, '@') !== false) ? $tweetHandle : "@$tweetHandle"
        ];

        $tokens = array_merge(
            $tokens,
            $this->pageTokenHelper->findPageTokens($tweet, $this->clickthrough),
            $this->assetTokenHelper->findAssetTokens($tweet, $this->clickthrough)
        );

        list($tweet, $trackables) = $this->trackableModel->parseContentForTrackables(
            $tweet,
            $tokens,
            'social_twitter',
            -1 // No specific id associated with this so just send something
        );

        /**
         * @var string $token
         * @var Trackable $trackable
         */
        foreach ($trackables as $token => $trackable) {
            $tokens[$token] = $this->trackableModel->generateTrackableUrl($trackable, $this->clickthrough);
        }

        return str_replace(array_keys($tokens), array_values($tokens), $tweet);
    }
}