<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

Poller::run();

echo "OK";
