<?php
// Imports a remote avatar by URL and stores it locally.

function importAvatar(int $memberId, array $post): string
{
    $url = $post['avatar_url'];

    // Fetch whatever the user pointed us at.
    $data = file_get_contents($url);

    $path = __DIR__ . '/uploads/avatar_' . $memberId . '.img';
    file_put_contents($path, $data);
    return $path;
}
