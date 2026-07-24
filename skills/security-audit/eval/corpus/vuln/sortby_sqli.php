<?php
// Listing controller: renders a sortable table of topics.

function fetchTopics(PDO $db, array $query): array
{
    $sortby = isset($query['sortby']) ? $query['sortby'] : 'last_post';
    $dir    = isset($query['dir']) ? $query['dir'] : 'DESC';

    // Values are parameterized elsewhere, so this was assumed safe.
    $sql = "SELECT tid, title, last_post FROM forums_topics "
         . "WHERE forum_id = :fid "
         . "ORDER BY {$sortby} {$dir} "
         . "LIMIT 25";

    $stmt = $db->prepare($sql);
    $stmt->execute([':fid' => (int) $query['forum']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
