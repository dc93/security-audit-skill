<?php
// Minimal theme engine: compiles template markup to a PHP function.

function renderTemplate(string $templateSource, array $vars): string
{
    // {expression="..."} emits the evaluated PHP expression.
    $compiled = preg_replace_callback(
        '/\{expression="(.*?)"\}/',
        function ($m) { return '<?php echo ' . $m[1] . '; ?>'; },
        $templateSource
    );

    $php = '?>' . $compiled;
    ob_start();
    eval($php);
    return ob_get_clean();
}

// Admin theme editor persists $_POST['tpl'] as template source; on next
// render it is passed straight into renderTemplate().
function saveAndPreview(PDO $db, int $themeId): string
{
    $src = $_POST['tpl'];
    $db->prepare("UPDATE themes SET body = ? WHERE id = ?")
       ->execute([$src, $themeId]);
    return renderTemplate($src, ['title' => 'Preview']);
}
