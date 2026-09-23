<?php
// Kalkulasi dashboard dengan aturan bisnis (diuji).
class Dashboard
{
    // $users = [['name' => string, 'statuses' => string[]], ...] (statuses = disposisi
    // yang ditujukan ke user). Return [{name, completed, pending}] hanya untuk yang
    // punya aktivitas (completed>0 || pending>0). SELESAI = completed, sisanya = pending.
    public static function userStats(array $users): array
    {
        $out = [];
        foreach ($users as $u) {
            $completed = 0;
            $pending = 0;
            foreach ($u['statuses'] as $s) {
                if ($s === 'SELESAI') $completed++;
                else $pending++;
            }
            if ($completed > 0 || $pending > 0) {
                $out[] = ['name' => $u['name'], 'completed' => $completed, 'pending' => $pending];
            }
        }
        return $out;
    }
}
