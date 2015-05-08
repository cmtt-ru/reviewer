<?php
namespace TJ;

use Exception;
use Flintstone\Flintstone;
use GuzzleHttp\Client as Guzzle;
use Maknz\Slack\Client as Slack;
use Psr\Log\LoggerInterface;

class Reviewer
{
    public $countries = [ 'ru' => 'Russia', 'us' => 'US', 'ua' => 'Ukraine', 'by' => 'Belarus' ];
    protected $appId;
    protected $client;
    protected $logger;
    protected $slackSettings;
    protected $storage;

    public function __construct($appId)
    {
        $this->appId = intval($appId);
        $this->client = new Guzzle(['defaults' => ['timeout' => 20, 'connect_timeout' => 10]]);
        $this->storage = Flintstone::load('reviews', ['dir' => __DIR__ . '/../storage']);
    }

    public function setSlackSettings($slackSettings)
    {
        $this->slackSettings = $slackSettings;

        return $this;
    }

    public function setLogger($logger)
    {
        $this->logger = $logger;

        return $this;
    }

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

                $slack->send();

                $this->storage->set("r{$review['id']}", true);
            } catch (Exception $e) {
                if ($this->logger) {
                    $this->logger->error('Reviewer: exception while sending reviews', [ 'exception' => $e ]);
                }
            }
        }
    }

    public function start()
    {
        $reviews = $this->getReviews();
        $this->sendReviews($reviews);

        if ($this->logger) {
            $this->logger->debug('Sent ' . count($reviews) . ' reviews');
        }
    }
}