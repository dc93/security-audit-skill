<?php
// Listing controller: sortable table, but the sort column is allowlisted
// and every value is bound.

function fetchTopics(PDO $db, array $query): array
{
    $allowedSort = ['last_post', 'title', 'started'];
    $sortby = in_array($query['sortby'] ?? '', $allowedSort, true)
        ? $query['sortby'] : 'last_post';
    $dir = (($query['dir'] ?? '') === 'asc') ? 'ASC' : 'DESC';

    $sql = "SELECT tid, title, last_post FROM forums_topics "
         . "WHERE forum_id = :fid "
         . "ORDER BY {$sortby} {$dir} "
         . "LIMIT 25";

    $stmt = $db->prepare($sql);
    $stmt->execute([':fid' => (int) ($query['forum'] ?? 0)]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
