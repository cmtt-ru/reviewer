# Reviewer
Simple library to track App Store reviews with [Slack](https://slack.com/).

[![License](https://poser.pugx.org/tjournal/reviewer/license)](https://packagist.org/packages/tjournal/reviewer)
[![Latest Stable Version](https://poser.pugx.org/tjournal/reviewer/v/stable)](https://packagist.org/packages/tjournal/reviewer)

### Installing via Composer
```bash
composer.phar require tjournal/reviewer
```

After installing, you should make database directory `storage` writable:

```bash
chmod 777 storage
```

and require Composer's autoloader:

```php
require 'vendor/autoload.php';
```

### Simple usage
You need to [create new Incoming webhook](https://slack.com/services/new/incoming-webhook) in Slack and change `{APPID}` with [the real app id](https://www.codeproof.com/blog/how-to-find-aitunes-store-id-or-appid/):

```php
try {
    $reviewer = new TJ\Reviewer({APPID});
    $reviewer->setSlackSettings(['endpoint' => 'https://hooks.slack.com/services/ABCDE/QWERTY', 'channel' => '#reviews']);
    $reviewer->start();
} catch (Exception $e) {
    // handle errors
}
```

Then add your script to crontab:

```bash
sudo crontab -e
*/15 * * * *  php crontab.php
```

### Monolog integration
If you want to track internal library errors you can use [Monolog](https://github.com/Seldaek/monolog). Here is the easiest way:

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$monolog = new Logger('Reviewer');
$monolog->pushHandler(new StreamHandler('/tmp/reviewer.log', Logger::DEBUG));

$reviewer->setLogger($monolog);
```

### Countries
There is a way to change set of countries from whence Reviewer is getting fresh app's reviews.

```php
try {
    $reviewer = new TJ\Reviewer({APPID});
    ...
    $reviewer->countries = ['ru' => 'Russia', 'us' => 'US', 'fi' => 'Finland', 'fr' => 'France'];

    $reviewer->start();
} catch (Exception $e) {
    // handle errors
}
```