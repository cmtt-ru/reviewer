<?php
namespace TJ;

use Exception;
use Flintstone\Flintstone;
use Flintstone\FlintstoneException;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Pool as Pool;
use Maknz\Slack\Client as Slack;
use Psr\Log\LoggerInterface;

/**
 * Send fresh reviews from the App Store to your Slack channel
 */
class Reviewer
{
    /**
     * List of countries to check
     *
     * @var array
     */
    public $countries = ['ru' => 'Russia', 'us' => 'US', 'ua' => 'Ukraine', 'by' => 'Belarus'];

    /**
     * Application id
     *
     * @var integer
     */
    protected $appId;

    /**
     * Max pages to request from itunes, default is 3
     *
     * @var integer
     */
    protected $maxPages;

    /**
     * Is sending fired for a first time
     *
     * @var boolean
     */
    protected $firstTime = false;

    /**
     * Exception caught during the initialization
     *
     * @var Exception
     */
    protected $initException;

    /**
     * List of Slack settings
     *
     * - string  $endpoint  required  Slack hook endpoint
     * - string  $channel   optional  Slack channel
     *
     * @var array
     */
    protected $slackSettings;

    /**
     * Guzzle instance
     *
     * @var Guzzle
     */
    protected $client;

    /**
     * Logger instance
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Flintstone instance
     *
     * @var Flintstone
     */
    protected $storage;

    /**
     * Create Guzzle and Flintstone objects
     *
     * @param  integer   $appId    application App Store id
     * @param  integer   $maxPages max pages count to check
     * @throws Exception when DB directory is not writable
     */
    public function __construct($appId, $maxPages = 3)
    {
        $this->appId = intval($appId);
        $this->maxPages = max(1, intval($maxPages));
        $this->client = new Guzzle(['defaults' => ['timeout' => 20, 'connect_timeout' => 10]]);

        $databaseDir = realpath(__DIR__ . '/..') . '/storage';

        if (!realpath($databaseDir) || !is_dir($databaseDir) || !is_writable($databaseDir)) {
            throw new Exception("Please make '{$databaseDir}' dir writable");
        }

        if (!file_exists($databaseDir . '/reviews.dat')) {
            $this->firstTime = true;
        }

        try {
            $this->storage = Flintstone::load('reviews', ['dir' => $databaseDir]);
        } catch (FlintstoneException $e) {
            $this->initException = $e;
        }
    }

    /**
     * Slack options setter
     *
     * @param  array         $slackSettings e.g. [ 'endpoint' => 'https://hook.slack.com/...', 'channel' => '#reviews' ]
     * @return TJ\Reviewer
     */
    public function setSlackSettings($slackSettings)
    {
        $this->slackSettings = $slackSettings;

        return $this;
    }

    /**
     * PSR-3 compatible logger setter
     *
     * @param  LoggerInterface $logger
     * @return TJ\Reviewer
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        if ($this->initException && $this->logger) {
            $this->logger->error('Reviewer: exception while init', ['exception' => $this->initException]);
            $this->initException = null;
        }

        return $this;
    }

    /**
     * Get reviews from country App Store
     *
     * @param  integer $appId
     * @param  string  $countryCode
     * @param  string  $countryName
     * @return array   list of reviews
     */
    public function getReviewsByCountry($appId, $countryCode, $countryName)
    {
        $reviews = [];

        $requests = [];
        for ($i = 1; $i <= $this->maxPages; $i++) {
            array_push($requests, $this->client->createRequest("GET", "https://itunes.apple.com/{$countryCode}/rss/customerreviews/page={$i}/id={$appId}/sortBy=mostRecent/json"));
        }

        try {
            $responses = Pool::batch($this->client, $requests);

            foreach ($responses->getSuccessful() as $page => $response) {
                $realPage = $page + 1;
                $reviewsData = $response->json();

                if (!isset($reviewsData['feed']) || !isset($reviewsData['feed']['entry']) || count($reviewsData['feed']['entry']) == 0) {
                    // Received empty page
                    if ($this->logger) {
                        $this->logger->debug("#{$appId}: Received 0 entries for page {$realPage} in {$countryName}");
                    }
                } else {
                    if ($this->logger) {
                        $countEntries = count($reviewsData['feed']['entry']) - 1;
                        $this->logger->debug("#{$appId}: Received {$countEntries} entries for page {$realPage} in {$countryName}");
                    }

                    $applicationData = [];
                    foreach ($reviewsData['feed']['entry'] as $reviewEntry) {
                        if (isset($reviewEntry['im:name']) && isset($reviewEntry['im:image']) && isset($reviewEntry['link'])) {
                            // First element is always an app metadata
                            $applicationData = [
                                'name' => $reviewEntry['im:name']['label'],
                                'image' => end($reviewEntry['im:image'])['label'],
                                'link' => $reviewEntry['link']['attributes']['href']
                            ];
                            continue;
                        }

                        $reviewId = intval($reviewEntry['id']['label']);
                        if ($this->storage->get("r{$reviewId}")) {
                            continue;
                        }

                        $review = [
                            'id' => $reviewId,
                            'author' => [
                                'uri' => $reviewEntry['author']['uri']['label'],
                                'name' => $reviewEntry['author']['name']['label']
                            ],
                            'title' => $reviewEntry['title']['label'],
                            'content' => $reviewEntry['content']['label'],
                            'rating' => intval($reviewEntry['im:rating']['label']),
                            'country' => $countryName,
                            'application' => array_merge($applicationData, ['version' => $reviewEntry['im:version']['label']])
                        ];

                        array_push($reviews, $review);
                    }
                }
            }
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Reviewer: exception while getting reviews', ['exception' => $e]);
            }
        }

        return $reviews;

    }

    /**
     * Get new reviews of the app
     *
     * @return array list of reviews
     */
    public function getReviews()
    {
        $appId = $this->appId;
        $reviews = [];

        foreach ($this->countries as $countryCode => $countryName) {
            $reviewsByCountry = $this->getReviewsByCountry($appId, $countryCode, $countryName);

            if (is_array($reviewsByCountry) && count($reviewsByCountry)) {
                $reviews = array_merge($reviews, $reviewsByCountry);
            }
        }

        return $reviews;
    }

    /**
     * Send reviews to Slack
     *
     * @param  array   $reviews   list of reviews to send
     * @return boolean successful sending
     */
    public function sendReviews($reviews)
    {
        if (!is_array($reviews) || !count($reviews)) {
            return false;
        }

        if (!isset($this->slackSettings['endpoint'])) {
            if ($this->logger) {
                $this->logger->error('Reviewer: you should set endpoint in Slack settings');
            }

            return false;
        }

        $config = [
            'username' => 'TJ Reviewer',
            'icon_url' => 'https://i.imgur.com/GX1ASZy.png'
        ];

        if (isset($this->slackSettings['channel'])) {
            $config['channel'] = $this->slackSettings['channel'];
        }

        $slack = new Slack($this->slackSettings['endpoint'], $config);

        foreach ($reviews as $review) {
            $ratingText = '';
            for ($i = 1; $i <= 5; $i++) {
                $ratingText .= ($i <= $review['rating']) ? "★" : "☆";
            }

            try {
                if ($this->firstTime === false) {
                    $slack->attach([
                        'fallback' => "{$ratingText} {$review['title']} — {$review['content']}",
                        'author_name' => $review['application']['name'],
                        'author_icon' => $review['application']['image'],
                        'author_link' => $review['application']['link'],
                        'color' => ($review['rating'] >= 4) ? 'good' : (($review['rating'] == 3) ? 'warning' : 'danger'),
                        'fields' => [
                            [
                                'title' => $review['title'],
                                'value' => $review['content']
                            ],
                            [
                                'title' => 'Rating',
                                'value' => $ratingText,
                                'short' => true
                            ],
                            [
                                'title' => 'Author',
                                'value' => "<{$review['author']['uri']}|{$review['author']['name']}>",
                                'short' => true
                            ],
                            [
                                'title' => 'Version',
                                'value' => $review['application']['version'],
                                'short' => true
                            ],
                            [
                                'title' => 'Country',
                                'value' => $review['country'],
                                'short' => true
                            ]
                        ]
                    ])->send();
                }

                $this->storage->set("r{$review['id']}", 1);
            } catch (Exception $e) {
                if ($this->logger) {
                    $this->logger->error('Reviewer: exception while sending reviews', ['exception' => $e]);
                }
            }
        }

        return true;
    }

    /**
     * Putting all the work together
     *
     * @return void
     */
    public function start()
    {
        $reviews = $this->getReviews();
        $this->sendReviews($reviews);

        if ($this->logger) {
            $this->logger->debug('Sent ' . count($reviews) . ' reviews');
        }
    }
}
