# Reviewer
Simple library to track App Store reviews with Slack.

>[Slack](https://slack.com/) is a platform for team communication

### Installing via Composer
```bash
composer.phar require tjournal/reviewer:~0.1
```

After installing, you need to require Composer's autoloader:

```php
require 'vendor/autoload.php';
```

### Simple usage
To track reviews just [create new Incoming webhook](https://slack.com/services/new/incoming-webhook) in Slack and add this code to crontab (once per hour):

```php
$reviewer = new TJ\Reviewer(683103523);
$reviewer->setSlackSettings(['endpoint' => 'https://hooks.slack.com/services/ABCDE/QWERTY', 'channel' => '#reviews']);
$reviewer->start();
```
