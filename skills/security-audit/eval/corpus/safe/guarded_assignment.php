<?php
// Account settings update with an explicit allowlist of writable fields.

class Member
{
    private const FILLABLE = ['name', 'email', 'signature', 'timezone'];

    protected $attributes = [];

    public function fillFromRequest(array $data): void
    {
        foreach (self::FILLABLE as $key) {
            if (array_key_exists($key, $data)) {
                $this->attributes[$key] = $data[$key];
            }
        }
    }

    public function save(PDO $db, int $id): void
    {
        $cols = [];
        $args = [];
        foreach ($this->attributes as $k => $v) {
            $cols[] = "`$k` = ?";
            $args[] = $v;
        }
        $args[] = $id;
        $db->prepare("UPDATE members SET " . implode(', ', $cols) . " WHERE id = ?")
           ->execute($args);
    }
}

function updateSettings(PDO $db, Member $me, int $myId): void
{
    $me->fillFromRequest($_POST);   // is_admin / group_id can never be written
    $me->save($db, $myId);
}
