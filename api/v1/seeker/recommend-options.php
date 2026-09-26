<?php
// api/v1/seeker/recommend-options.php
// GET — form-population data for the "Find My Match" DSS screen: the list
// of cities that have at least one active provider, and the current
// min/max price across active listings (shown as a budget hint). Guest-
// accessible, same as recommend.php itself. Categories are NOT duplicated
// here — the client already has /categories/index.php for that.

require_once dirname(__DIR__, 1) . '/_bootstrap.php';

allow('GET');

$db = db();

$cities = $db->query(
    "SELECT DISTINCT city FROM providers
     WHERE status = 'active' AND city IS NOT NULL AND city != ''
     ORDER BY city"
)->fetchAll(PDO::FETCH_COLUMN);

$bounds = $db->query(
    "SELECT MIN(price) AS min_price, MAX(price) AS max_price
     FROM services WHERE status = 'active'"
)->fetch(PDO::FETCH_ASSOC);

ok([
    'data' => [
        'cities'    => $cities,
        'min_price' => $bounds['min_price'] !== null ? (float) $bounds['min_price'] : null,
        'max_price' => $bounds['max_price'] !== null ? (float) $bounds['max_price'] : null,
    ],
]);
