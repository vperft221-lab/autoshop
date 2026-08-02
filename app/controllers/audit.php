<?php
/** Audit log (admin only) */

function audit_index(): void
{
    require_role(['admin']);
    $rows = all("SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 200");
    $table = data_table([
        'When'   => fn($r) => dt($r['created_at']),
        'User'   => fn($r) => e($r['user_name'] ?? 'system'),
        'Action' => fn($r) => badge(ucwords(str_replace('_', ' ', $r['action'])), 'blue'),
        'Entity' => fn($r) => e($r['entity'] ? $r['entity'] . ($r['entity_id'] ? ' #' . (int)$r['entity_id'] : '') : '—'),
        'Details'=> fn($r) => e($r['details'] ?? '—'),
        'IP'     => fn($r) => e($r['ip'] ?? '—'),
    ], $rows, 'No activity recorded yet.');
    $content = '<p class="muted">Showing the 200 most recent events. Every create, update, status change, payment and sign-in is logged for accountability.</p>' . $table;
    echo layout('Audit Log', $content, 'audit');
}
