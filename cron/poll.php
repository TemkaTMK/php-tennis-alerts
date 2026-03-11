<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

Telegram::send('TEST FROM PHP');

Poller::run();

echo "OK";
