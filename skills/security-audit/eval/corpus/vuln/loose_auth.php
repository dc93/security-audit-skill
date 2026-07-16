<?php
// Verifies an account-activation / unsubscribe token from the URL.

function verifyToken(string $expected, array $query): bool
{
    if (!isset($query['token'])) {
        return false;
    }
    // Loose comparison: "0e123" == "0e456" is true, and an array yields null.
    return $query['token'] == $expected;
}

function activate(PDO $db, array $query): void
{
    $row = $db->query("SELECT id, activation_token FROM members WHERE id = "
        . (int) $query['id'])->fetch(PDO::FETCH_ASSOC);

    if ($row && verifyToken($row['activation_token'], $query)) {
        $db->prepare("UPDATE members SET activated = 1 WHERE id = ?")
           ->execute([$row['id']]);
    }
}
