<?php
require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('POST');

auth();

ok(['message' => 'Logged out successfully']);
