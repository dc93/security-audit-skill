<?php
// Renders a user's display name into an HTML page, escaped.

function renderProfileHeader(string $displayName, string $bioHtmlAllowed): string
{
    $name = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');

    // Bio was sanitized to an allowlist of tags on write by a vetted purifier;
    // here it is only concatenated after escaping the untrusted name.
    return "<h1>" . $name . "</h1>\n"
         . "<div class=\"bio\">" . $bioHtmlAllowed . "</div>";
}
