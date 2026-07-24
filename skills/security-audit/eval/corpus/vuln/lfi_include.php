<?php
// Front controller: dispatches to a "page" module by name.

function dispatch(array $query): void
{
    $page = isset($query['page']) ? $query['page'] : 'home';

    // Loads applications/<page>/index.php — assumed to be a fixed set.
    include __DIR__ . '/pages/' . $page . '.php';
}

// GET /?page=home  → pages/home.php
// The page name comes straight from the query string.
dispatch($_GET);
