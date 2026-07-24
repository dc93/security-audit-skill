<?php
// Account settings update.

class Member
{
    protected $attributes = [];

    public function fill(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->attributes[$key] = $value;
        }
    }

    public function save(PDO $db): void
    {
        $cols = [];
        $args = [];
        foreach ($this->attributes as $k => $v) {
            $cols[] = "`$k` = ?";
            $args[] = $v;
        }
        $args[] = $this->attributes['id'];
        $db->prepare("UPDATE members SET " . implode(', ', $cols) . " WHERE id = ?")
           ->execute($args);
    }
}

// Handler for POST /account/settings
function updateSettings(PDO $db, Member $me): void
{
    $me->fill($_POST);   // name, email, ... and whatever else was posted
    $me->save($db);
}
