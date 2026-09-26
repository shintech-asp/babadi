<?php
// includes/dss_helper.php
//
// Decision Support System — scoring engine shared by seeker/recommend.php
// (web) and, later, api/v1/seeker/recommend.php (mobile). Neither caller
// should duplicate this logic — see CLAUDE.md's note on the three
// independently-drifting control-number implementations for why.
//
// Phase 1: structured filters + deterministic scoring (dssRank/dssScoreListing
// below — these never touch Groq and are the sole source of ranking, by
// design: an LLM interprets, it never decides who wins).
// Phase 3/4: dssParseNeedGroq()/dssGenerateExplanationsGroq() add hybrid
// free-text parsing (English/Tagalog/Taglish) and richer explanations on
// top, both fully optional — every caller degrades to the rule parser /
// template explanation when Groq is disabled, unreachable, or wrong.

require_once __DIR__ . '/groq_client.php';

/**
 * Keyword map for the free-text/category matching criterion (C1).
 * Keyed by service_categories.name (case-insensitive match against the
 * live category list is done by the caller); Tagalog/Taglish terms
 * included since this is a Cavite-only marketplace.
 */
function dssCategoryKeywords(): array {
    return [
        'General Pest Control' => ['pest', 'insect', 'general', 'peste'],
        'Termite Control'      => ['termite', 'anay', 'wood borer', 'powder post'],
        'Rodent Control'       => ['rat', 'rats', 'mouse', 'mice', 'daga', 'rodent'],
        'Cockroach Control'    => ['roach', 'cockroach', 'ipis'],
        'Mosquito Control'     => ['mosquito', 'lamok', 'dengue'],
        'Bed Bug Control'      => ['bed bug', 'bedbug', 'surot'],
    ];
}

/**
 * Weight profiles for Simple Additive Weighting (SAW). Values sum to 100
 * (eco is a flat bonus on top, applied separately — see dssScoreListing()).
 * 'urgent' is auto-selected when urgency === 'emergency', overriding
 * whatever $priority the seeker picked, since an active infestation makes
 * speed the dominant concern regardless of stated preference.
 */
function dssWeightProfiles(): array {
    return [
        'balanced'   => ['category' => 30, 'rating' => 25, 'price' => 20, 'reliability' => 15, 'recency' => 10, 'urgency' => 0],
        'best_rated' => ['category' => 25, 'rating' => 38, 'price' => 10, 'reliability' => 17, 'recency' => 10, 'urgency' => 0],
        'best_value' => ['category' => 25, 'rating' => 20, 'price' => 38, 'reliability' => 12, 'recency' => 5,  'urgency' => 0],
        'urgent'     => ['category' => 25, 'rating' => 20, 'price' => 13, 'reliability' => 14, 'recency' => 8,  'urgency' => 20],
    ];
}

function dssResolveWeights(string $priority, string $urgency): array {
    $profiles = dssWeightProfiles();
    if ($urgency === 'emergency') {
        return $profiles['urgent'];
    }
    return $profiles[$priority] ?? $profiles['balanced'];
}

/**
 * Rule-based parse of free text into a category id, used before ever
 * considering an LLM call (Phase 3 hybrid parser). Returns null category_id
 * when nothing matches — the caller falls back to "no category filter".
 */
function dssParseNeedRules(string $needText, array $categories): array {
    $needText = strtolower(trim($needText));
    $bestCategoryId = null;
    $bestHits = 0;

    foreach ($categories as $cat) {
        $keywords = dssCategoryKeywords()[$cat['name']] ?? [];
        $hits = 0;
        foreach ($keywords as $kw) {
            if ($needText !== '' && strpos($needText, $kw) !== false) {
                $hits++;
            }
        }
        if ($hits > $bestHits) {
            $bestHits = $hits;
            $bestCategoryId = (int)$cat['id'];
        }
    }

    return [
        'category_id' => $bestCategoryId,
        'confidence'  => $bestHits > 0 ? min(1.0, 0.5 + 0.15 * $bestHits) : 0.0,
    ];
}

/**
 * Groq fallback for when the rule parser has low/no confidence — e.g. no
 * exact keyword hit, indirect phrasing ("may kumakalabog sa dingding"),
 * typos, or pure Tagalog/Taglish the keyword map doesn't cover. Only ever
 * called by the caller when needed (see recommend.php), never on every
 * request — that's what keeps this within Groq's free-tier limits.
 *
 * Returns null on any failure (disabled, no key, timeout, bad response) —
 * caller must keep the rule parser's result in that case. category_id is
 * re-validated against the real category id set; a hallucinated id is
 * discarded rather than trusted, since this only ever produces a *filter*,
 * never a score.
 */
function dssParseNeedGroq(string $needText, array $categories): ?array {
    if (trim($needText) === '') {
        return null;
    }

    $categoryListText = implode("\n", array_map(
        fn($c) => (int)$c['id'] . ': ' . $c['name'],
        $categories
    ));
    $validIds = array_map(fn($c) => (int)$c['id'], $categories);

    $systemPrompt = "You are a pest-control triage assistant for Pestify, a marketplace serving Cavite, Philippines. "
        . "Seekers describe their problem in English, Tagalog, or Taglish (a natural mix of both) — understand all three fluently. "
        . "Given the seeker's description, pick the single best-matching category from this list, or null if truly none fits:\n"
        . $categoryListText . "\n\n"
        . 'Respond with ONLY this JSON shape, no other text: {"category_id": <int or null>, "confidence": <0.0-1.0>, "pest_terms": ["..."]}. '
        . "pest_terms is up to 3 short words/phrases (in whatever language the seeker used) that indicate the pest problem.";

    $result = groqChat([
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $needText],
    // gpt-oss-20b is a reasoning model — it spends a chunk of the token
    // budget on hidden chain-of-thought before emitting the final JSON, so
    // this needs far more headroom than the ~30-token answer would suggest.
    ], ['json' => true, 'max_tokens' => 600, 'temperature' => 0.1]);

    if ($result === null) {
        return null;
    }

    $categoryId = $result['category_id'] ?? null;
    if ($categoryId !== null) {
        $categoryId = (int)$categoryId;
        if (!in_array($categoryId, $validIds, true)) {
            error_log('[dss_helper] Groq returned an out-of-range category_id: ' . var_export($result['category_id'], true));
            $categoryId = null;
        }
    }

    $confidence = is_numeric($result['confidence'] ?? null) ? max(0.0, min(1.0, (float)$result['confidence'])) : 0.0;

    $pestTerms = [];
    if (is_array($result['pest_terms'] ?? null)) {
        foreach (array_slice($result['pest_terms'], 0, 3) as $term) {
            if (is_string($term) && trim($term) !== '') {
                $pestTerms[] = trim(substr($term, 0, 40));
            }
        }
    }

    return ['category_id' => $categoryId, 'confidence' => $confidence, 'pest_terms' => $pestTerms];
}

/**
 * Pulls every active listing with pre-aggregated review/job stats in one
 * query (no N+1). Caller filters/scores in PHP — at this app's scale
 * (single digits to low hundreds of listings) that's microseconds and far
 * easier to unit-test than the equivalent SQL arithmetic.
 */
function dssFetchCandidates(PDO $db): array {
    $sql = "SELECT sl.id AS listing_id, sl.provider_id, sl.category_id, sl.service_name AS title,
                   sl.description, sl.price, sl.is_eco_friendly, sl.is_emergency_available,
                   sl.images,
                   p.company_name, p.logo_url, p.city, p.verification_status,
                   sc.name AS category_name,
                   COALESCE(agg.review_count, 0) AS review_count,
                   COALESCE(agg.avg_rating, 0)   AS avg_rating,
                   agg.last_review_at,
                   COALESCE(jobs.total_jobs, 0)     AS total_jobs,
                   COALESCE(jobs.completed_count, 0) AS completed_count,
                   COALESCE(jobs.cancelled_count, 0) AS cancelled_count
            FROM services sl
            JOIN providers p ON p.id = sl.provider_id
            LEFT JOIN service_categories sc ON sc.id = sl.category_id
            LEFT JOIN (
                SELECT provider_id, COUNT(*) AS review_count, AVG(rating) AS avg_rating,
                       MAX(created_at) AS last_review_at
                FROM service_reviews GROUP BY provider_id
            ) agg ON agg.provider_id = p.id
            LEFT JOIN (
                SELECT provider_id, COUNT(*) AS total_jobs,
                       SUM(status = 'completed') AS completed_count,
                       SUM(status = 'cancelled') AS cancelled_count
                FROM availed_services GROUP BY provider_id
            ) jobs ON jobs.provider_id = p.id
            WHERE sl.status = 'active'
              AND p.status = 'active'
              AND p.verification_status = 'approved'
            LIMIT 500";

    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Platform-wide average rating, used as the Bayesian prior (C2) so a
 * brand-new provider with 0 reviews lands at the platform mean instead of
 * being scored 0 (which would bury them permanently — nobody ever gets a
 * first review) or 5 (which would let them leapfrog proven providers).
 */
function dssPlatformAverageRating(PDO $db): float {
    $avg = $db->query("SELECT AVG(rating) FROM service_reviews")->fetchColumn();
    return $avg !== null && $avg !== false ? (float)$avg : 4.0;
}

/**
 * Scores one candidate listing against the seeker's filters. Returns the
 * total (0-100, +8 eco bonus) plus a per-criterion breakdown for the
 * "Why this?" transparency panel.
 */
function dssScoreListing(array $listing, array $filters, array $weights, float $platformAvgRating): array {
    $breakdown = [];

    // C1 — category / need match
    if ($filters['category_id'] !== null) {
        if ((int)$listing['category_id'] === (int)$filters['category_id']) {
            $catRaw = 1.0;
            $catLabel = 'Exact match: ' . $listing['category_name'];
        } else {
            $catRaw = 0.15;
            $catLabel = 'Different category (' . $listing['category_name'] . ')';
        }
    } else {
        $catRaw = 0.6;
        $catLabel = 'No specific category requested';
    }
    $breakdown['category'] = ['raw' => $catRaw, 'points' => round($catRaw * $weights['category'], 1), 'label' => $catLabel];

    // C2 — rating, Bayesian-shrunk toward the platform mean (m=5 phantom reviews)
    $m = 5;
    $reviewCount = (int)$listing['review_count'];
    $avgRating   = (float)$listing['avg_rating'];
    $shrunkRating = $reviewCount > 0
        ? (($reviewCount / ($reviewCount + $m)) * $avgRating) + (($m / ($reviewCount + $m)) * $platformAvgRating)
        : $platformAvgRating;
    $ratingRaw = max(0, min(1, ($shrunkRating - 1) / 4));
    $ratingLabel = $reviewCount > 0
        ? round($avgRating, 1) . '★ from ' . $reviewCount . ' review' . ($reviewCount === 1 ? '' : 's')
        : 'No reviews yet — scored at platform average';
    $breakdown['rating'] = ['raw' => $ratingRaw, 'points' => round($ratingRaw * $weights['rating'], 1), 'label' => $ratingLabel];

    // C3 — price fit against the seeker's max budget (min assumed 0)
    $price = (float)($listing['price'] ?? 0);
    $budgetMax = $filters['budget_max'];
    if ($budgetMax === null || $budgetMax <= 0) {
        $priceRaw = 0.75;
        $priceLabel = '₱' . number_format($price, 0) . ' (no budget set)';
    } elseif ($price <= $budgetMax) {
        $priceRaw = 1.0;
        $priceLabel = '₱' . number_format($price, 0) . ' — within your ₱' . number_format($budgetMax, 0) . ' budget';
    } else {
        $overBy = $price - $budgetMax;
        $priceRaw = max(0, 1 - ($overBy / (0.5 * $budgetMax)));
        $priceLabel = '₱' . number_format($price, 0) . ' — ₱' . number_format($overBy, 0) . ' over your budget';
    }
    $breakdown['price'] = ['raw' => $priceRaw, 'points' => round($priceRaw * $weights['price'], 1), 'label' => $priceLabel];

    // C4 — reliability: completion rate (shrunk) + job volume, from booking history
    $completed  = (int)$listing['completed_count'];
    $cancelled  = (int)$listing['cancelled_count'];
    $totalJobs  = (int)$listing['total_jobs'];
    $completionRate = ($completed + 3 * 0.8) / max(1, $completed + $cancelled + 3);
    $volumeScore    = min(1, log(1 + $totalJobs) / log(21));
    $reliabilityRaw = 0.7 * $completionRate + 0.3 * $volumeScore;
    $reliabilityLabel = $totalJobs > 0
        ? $completed . ' of ' . $totalJobs . ' jobs completed'
        : 'No booking history yet';
    $breakdown['reliability'] = ['raw' => $reliabilityRaw, 'points' => round($reliabilityRaw * $weights['reliability'], 1), 'label' => $reliabilityLabel];

    // C5 — review recency
    if (!empty($listing['last_review_at'])) {
        $daysSince = (time() - strtotime($listing['last_review_at'])) / 86400;
        $recencyRaw = exp(-$daysSince / 180);
        $recencyLabel = 'Last review ' . max(0, (int)round($daysSince)) . ' days ago';
    } else {
        $recencyRaw = 0.5;
        $recencyLabel = 'No reviews yet';
    }
    $breakdown['recency'] = ['raw' => $recencyRaw, 'points' => round($recencyRaw * $weights['recency'], 1), 'label' => $recencyLabel];

    // C6 — urgency fit (only carries weight when the 'urgent' profile is active)
    $urgencyRaw = !empty($listing['is_emergency_available']) ? 1.0 : 0.0;
    $urgencyLabel = !empty($listing['is_emergency_available']) ? 'Available for emergency service' : 'Not offered for emergency service';
    $breakdown['urgency'] = ['raw' => $urgencyRaw, 'points' => round($urgencyRaw * $weights['urgency'], 1), 'label' => $urgencyLabel];

    $total = array_sum(array_column($breakdown, 'points'));

    // Eco is a preference, not a criterion — flat bonus on top of 100 so
    // scores stay comparable across queries whether or not eco was requested.
    if (!empty($filters['eco_only']) && !empty($listing['is_eco_friendly'])) {
        $total += 8;
        $breakdown['eco'] = ['raw' => 1.0, 'points' => 8, 'label' => 'Eco-friendly (bonus)'];
    }

    return ['total' => round($total, 1), 'breakdown' => $breakdown];
}

/**
 * Pulls up to $perProvider real review quotes (+ photo, if any) per
 * provider for the final result set — this is what actually helps a
 * seeker decide beyond a bare score, so it's fetched for every result,
 * not hidden behind an extra click. Prefers reviews that have text and/or
 * a photo over bare star-only ratings, then most recent.
 */
function dssFetchReviewSnippets(PDO $db, array $providerIds, int $perProvider = 2): array {
    if (empty($providerIds)) return [];

    $placeholders = implode(',', array_fill(0, count($providerIds), '?'));
    $stmt = $db->prepare(
        "SELECT r.provider_id, r.rating, r.feedback, r.feedback_image, r.created_at,
                u.first_name, u.last_name
         FROM service_reviews r
         LEFT JOIN users u ON u.id = r.seeker_user_id
         WHERE r.provider_id IN ($placeholders)
         ORDER BY (r.feedback IS NOT NULL AND r.feedback != '') DESC,
                  (r.feedback_image IS NOT NULL) DESC,
                  r.created_at DESC"
    );
    $stmt->execute($providerIds);

    $snippets = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = $row['provider_id'];
        if (!isset($snippets[$pid])) $snippets[$pid] = [];
        if (count($snippets[$pid]) < $perProvider) $snippets[$pid][] = $row;
    }
    return $snippets;
}

/**
 * Runs the full pipeline: fetch candidates, score, sort, cap to at most 2
 * listings per provider (so one provider can't own the whole results page),
 * truncate to $limit. $filters keys: category_id, budget_max, urgency,
 * priority, eco_only.
 */
function dssRank(PDO $db, array $filters, int $limit = 8): array {
    $allCandidates = dssFetchCandidates($db);
    $weights = dssResolveWeights($filters['priority'], $filters['urgency']);
    $platformAvg = dssPlatformAverageRating($db);

    // City is a hard filter, but degrade gracefully to the unfiltered set
    // rather than showing an empty page if nobody matches — this app has
    // very few providers per city right now.
    $degraded = false;
    $candidates = $allCandidates;
    if (!empty($filters['city'])) {
        $inCity = array_filter($allCandidates, fn($l) => strcasecmp((string)$l['city'], (string)$filters['city']) === 0);
        if (count($inCity) > 0) {
            $candidates = array_values($inCity);
        } else {
            $degraded = true;
        }
    }

    $scored = [];
    foreach ($candidates as $listing) {
        $result = dssScoreListing($listing, $filters, $weights, $platformAvg);
        $scored[] = array_merge($listing, ['score' => $result['total'], 'breakdown' => $result['breakdown']]);
    }

    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

    // Cap 2 per provider to keep the results diverse.
    $perProviderCount = [];
    $capped = [];
    foreach ($scored as $row) {
        $pid = $row['provider_id'];
        $perProviderCount[$pid] = ($perProviderCount[$pid] ?? 0) + 1;
        if ($perProviderCount[$pid] <= 2) {
            $capped[] = $row;
        }
        if (count($capped) >= $limit) break;
    }

    $reviewSnippets = dssFetchReviewSnippets($db, array_values(array_unique(array_column($capped, 'provider_id'))));
    foreach ($capped as &$row) {
        $row['reviews'] = $reviewSnippets[$row['provider_id']] ?? [];
    }
    unset($row);

    return ['results' => $capped, 'candidate_count' => count($candidates), 'degraded' => $degraded];
}

/**
 * Template-composed explanation, used when Groq is unavailable/disabled
 * (Phase 1 has no Groq integration at all — this is the only explanation
 * path right now, and stays the always-on fallback in later phases).
 */
function dssTemplateExplanation(array $listing): string {
    $b = $listing['breakdown'];
    $parts = [];

    if (($b['category']['raw'] ?? 0) >= 1.0) {
        $parts[] = 'a great match for ' . $listing['category_name'];
    }
    if ((int)$listing['review_count'] > 0) {
        $parts[] = 'rated ' . round((float)$listing['avg_rating'], 1) . '★ across ' . $listing['review_count'] . ' review' . ($listing['review_count'] == 1 ? '' : 's');
    } else {
        $parts[] = 'new on Pestify with no reviews yet';
    }
    if (($b['price']['raw'] ?? 0) >= 1.0) {
        $parts[] = 'priced at ₱' . number_format((float)$listing['price'], 0) . ', within your budget';
    } elseif (($b['price']['raw'] ?? 0) > 0) {
        $parts[] = 'priced at ₱' . number_format((float)$listing['price'], 0) . ', slightly above your budget';
    }
    if ((int)$listing['total_jobs'] > 0) {
        $parts[] = $listing['completed_count'] . ' of ' . $listing['total_jobs'] . ' bookings completed';
    }

    if (empty($parts)) {
        return 'This listing scored well overall based on your preferences.';
    }
    return ucfirst($parts[0]) . ' — ' . implode(', ', array_slice($parts, 1)) . '.';
}

/**
 * One batched Groq call for all ranked results — never one call per item,
 * that would multiply API usage by the result count for no benefit.
 * Returns [rank => explanation] using ONLY the deterministic breakdown
 * already computed (never the raw listing corpus), so a hallucinated
 * explanation can at worst misstate prose about facts it was handed —
 * it cannot invent a fact or change a ranking. Returns null on any
 * failure; caller keeps dssTemplateExplanation()'s output for every rank
 * that's null or missing.
 */
function dssGenerateExplanationsGroq(array $results): ?array {
    if (empty($results)) {
        return null;
    }

    $items = [];
    foreach ($results as $i => $r) {
        $b = $r['breakdown'];

        // Up to 2 short real review quotes — this is what actually makes an
        // explanation feel specific instead of generic score-recitation.
        $quotes = [];
        foreach (($r['reviews'] ?? []) as $rev) {
            if (!empty($rev['feedback'])) {
                $quotes[] = ['rating' => (int)$rev['rating'], 'text' => substr($rev['feedback'], 0, 140)];
            }
            if (count($quotes) >= 2) break;
        }

        $items[] = [
            'rank'    => $i + 1,
            'title'   => $r['title'],
            'company' => $r['company_name'],
            'price'   => (float)$r['price'],
            'facts'   => [
                'category_match'      => $b['category']['label'] ?? null,
                'rating_summary'      => $b['rating']['label'] ?? null,
                'price_fit'           => $b['price']['label'] ?? null,
                'reliability'         => $b['reliability']['label'] ?? null,
                'review_recency'      => $b['recency']['label'] ?? null,
                'emergency_available' => !empty($r['is_emergency_available']),
                'eco_friendly'        => !empty($r['is_eco_friendly']),
                'review_quotes'       => $quotes,
            ],
        ];
    }

    $systemPrompt = "You write short, helpful explanations for a pest-control provider recommendation list on Pestify "
        . "(a marketplace in Cavite, Philippines). The reader is a seeker deciding which provider to book. "
        . "For each item below, write TWO to THREE sentences addressed to the reader as \"you\" — but every fact "
        . "(rating, reviews, jobs completed, price, category, eco/emergency) describes the PROVIDER, never the reader "
        . "(say \"they have completed X jobs\", never \"you have no reviews\"). "
        . "Draw on the FULL range of facts given, not just rating — mention category fit, price fit, reliability/track "
        . "record, and eco or emergency availability whenever they're notably good or notably a tradeoff, so each "
        . "explanation reads differently based on what actually stands out about that item. If review_quotes are "
        . "given, naturally paraphrase or lightly quote what a real past customer said (do not fabricate a quote "
        . "that isn't there). Use ONLY the facts given for that item — never invent a fact, never compare an item to "
        . "others not listed, never mention a numeric score. "
        . 'Respond with ONLY this JSON shape: {"explanations": {"<rank>": "text", ...}}.';

    $result = groqChat([
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => json_encode($items)],
    // Same reasoning-model token overhead as dssParseNeedGroq() above,
    // multiplied by however many results are on the page — bumped further
    // than the old rating-only version since each explanation now draws on
    // more facts and runs 2-3 sentences instead of 2.
    ], ['json' => true, 'max_tokens' => 800 + 450 * count($items), 'temperature' => 0.4]);

    if ($result === null || !is_array($result['explanations'] ?? null)) {
        return null;
    }

    $out = [];
    foreach ($result['explanations'] as $rank => $text) {
        if (is_string($text) && trim($text) !== '') {
            $out[(int)$rank] = trim($text);
        }
    }
    return $out;
}

/**
 * Logs a query + its results to dss_queries/dss_results for later CTR
 * and conversion analysis (Phase 5). Non-fatal on failure. $userId is
 * null for guest (not-logged-in) queries — the feature works without an
 * account; only booking a listing requires login (enforced further down
 * the existing flow, unchanged here).
 */
function dssLogQuery(PDO $db, ?int $userId, array $filters, string $parsedBy, array $rankedResults): ?int {
    try {
        $db->prepare(
            'INSERT INTO dss_queries
                (user_id, need_text, category_id, budget_max, urgency, priority, eco_only, city, parsed_by, candidate_count, created_at)
             VALUES (:uid, :need_text, :category_id, :budget_max, :urgency, :priority, :eco_only, :city, :parsed_by, :candidate_count, NOW())'
        )->execute([
            ':uid'             => $userId,
            ':need_text'       => $filters['need_text'] ?? null,
            ':category_id'     => $filters['category_id'],
            ':budget_max'      => $filters['budget_max'],
            ':urgency'         => $filters['urgency'],
            ':priority'        => $filters['priority'],
            ':eco_only'        => !empty($filters['eco_only']) ? 1 : 0,
            ':city'            => $filters['city'] ?? null,
            ':parsed_by'       => $parsedBy,
            ':candidate_count' => $rankedResults['candidate_count'],
        ]);
        $queryId = (int)$db->lastInsertId();

        $stmt = $db->prepare(
            'INSERT INTO dss_results (query_id, listing_id, provider_id, rank_pos, total_score, breakdown_json, created_at)
             VALUES (:qid, :lid, :pid, :rank, :score, :breakdown, NOW())'
        );
        foreach ($rankedResults['results'] as $i => $row) {
            $stmt->execute([
                ':qid'       => $queryId,
                ':lid'       => $row['listing_id'],
                ':pid'       => $row['provider_id'],
                ':rank'      => $i + 1,
                ':score'     => $row['score'],
                ':breakdown' => json_encode($row['breakdown']),
            ]);
        }

        return $queryId;
    } catch (Throwable $e) {
        error_log('[dss_helper] Failed to log DSS query: ' . $e->getMessage());
        return null;
    }
}
