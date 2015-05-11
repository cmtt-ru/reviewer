<?php
namespace TJ;

use Exception;
use Flintstone\Flintstone;
use Flintstone\FlintstoneException;
use GuzzleHttp\Client as Guzzle;
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
    public $countries = [ 'ru' => 'Russia', 'us' => 'US', 'ua' => 'Ukraine', 'by' => 'Belarus' ];

    /**
     * Application id
     *
     * @var integer
     */
    protected $appId;

    /**
     * Is sending fired for a first time
     *
     * @var boolean
     */
    protected $firstTime = false;

    /**
     * Exception cathced during the initialization
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
     * @param integer $appId application App Store id
     * @throws Exception when DB directory is not writable
     */
    public function __construct($appId)
    {
        $this->appId = intval($appId);
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
     * @param  array $slackSettings e.g. [ 'endpoint' => 'https://hook.slack.com/...', 'channel' => '#reviews' ]
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
     * @param LoggerInterface $logger
     * @return TJ\Reviewer
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        if ($this->initException && $this->logger) {
            $this->logger->error('Reviewer: exception while init', [ 'exception' => $this->initException ]);
            $this->initException = null;
        }

        return $this;
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
            try {
                $response = $this->client->get("https://itunes.apple.com/{$countryCode}/rss/customerreviews/id={$appId}/sortBy=mostRecent/json");
                $reviewsData = $response->json();

                // todo проверять несколько страниц

                if (!isset($reviewsData['feed']) || !isset($reviewsData['feed']['entry']) || count($reviewsData['feed']['entry']) == 0) {
                    continue;
                }

                $applicationData = [];
                foreach ($reviewsData['feed']['entry'] as $reviewEntry) {
                    if (isset($reviewEntry['im:name']) && isset($reviewEntry['im:image'])) {
                        // First element is always an app metadata
                        $applicationData = [
                            'name' => $reviewEntry['im:name']['label'],
                            'image' => end($reviewEntry['im:image'])['label'],
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
            } catch (Exception $e) {
                if ($this->logger) {
                    $this->logger->error('Reviewer: exception while getting reviews', [ 'exception' => $e ]);
                }
            }
        }

        return $reviews;
    }

    /**
     * Send reviews to Slack
     *
     * @param  array $reviews list of reviews to send
     *
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

        foreach ($reviews as $review) {
            $ratingText = '';
            for ($i = 1; $i <= 5; $i++) {
                $ratingText .= ($i <= $review['rating']) ? "★" : "☆";
            }

            $config = [
                'username' => $review['application']['name'],
                'icon' => $review['application']['image']
            ];

            if (isset($this->slackSettings['channel'])) {
                $config['channel'] = $this->slackSettings['channel'];
            }

            try {
                $slack = new Slack($this->slackSettings['endpoint']);
                $slack->attach([
                    'fallback' => "{$ratingText} {$review['author']['name']}: {$review['title']} — {$review['content']}",
                    'color' => ($review['rating'] >= 4) ? 'good' : (($review['rating'] == 3) ? 'warning' : 'danger'),
                    'pretext' => "{$ratingText} Review for {$review['application']['version']} from <{$review['author']['uri']}|{$review['author']['name']}>",
                    'fields' => [
                        [
                            'title' => $review['title'],
                            'value' => $review['content']
                        ]
                    ]
                ]);

                if ($this->firstTime === true) {
                    $slack->send();
                }

                $this->storage->set("r{$review['id']}", 1);
            } catch (Exception $e) {
                if ($this->logger) {
                    $this->logger->error('Reviewer: exception while sending reviews', [ 'exception' => $e ]);
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