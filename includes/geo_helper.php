<?php
// Shared Cavite geofence check — used by both the web and mobile provider
// registration flows to validate a provider's submitted business location.
// Previously this lived only inside auth/register.php; the mobile endpoint
// (api/v1/auth/register.php) never created a providers row at all, so there
// was nothing to validate yet. Extracted here so the two never drift apart,
// the same lesson as every other "N independent implementations" bug
// documented in CLAUDE.md's Recent Work Log.

if (!function_exists('getCavitePolygon')) {
    function getCavitePolygon() {
        return [
            [14.0534, 120.5648],
            [14.1022, 120.6246],
            [14.1375, 120.6842],
            [14.1718, 120.7374],
            [14.2205, 120.7588],
            [14.2769, 120.7861],
            [14.3369, 120.8190],
            [14.4002, 120.8587],
            [14.4458, 120.9198],
            [14.4799, 120.9643],
            [14.5080, 121.0142],
            [14.4874, 121.0719],
            [14.4409, 121.0740],
            [14.3838, 121.0583],
            [14.3232, 121.0329],
            [14.2728, 121.0092],
            [14.2219, 120.9837],
            [14.1718, 120.9598],
            [14.1299, 120.9361],
            [14.0922, 120.9063],
            [14.0736, 120.8616],
            [14.0598, 120.7992],
            [14.0517, 120.7308],
            [14.0470, 120.6540],
            [14.0534, 120.5648]
        ];
    }
}

if (!function_exists('isInsidePolygon')) {
    function isInsidePolygon($lat, $lng, $polygon) {
        $inside = false;
        $j = count($polygon) - 1;

        for ($i = 0; $i < count($polygon); $j = $i++) {
            $yi = (float)$polygon[$i][0];
            $xi = (float)$polygon[$i][1];
            $yj = (float)$polygon[$j][0];
            $xj = (float)$polygon[$j][1];

            $intersects = (($yi > $lat) !== ($yj > $lat))
                && ($lng < (($xj - $xi) * ($lat - $yi)) / ($yj - $yi) + $xi);

            if ($intersects) {
                $inside = !$inside;
            }
        }

        return $inside;
    }
}

if (!function_exists('isInCavite')) {
    function isInCavite($lat, $lng): bool {
        if (!is_numeric($lat) || !is_numeric($lng)) return false;
        return isInsidePolygon((float)$lat, (float)$lng, getCavitePolygon());
    }
}
