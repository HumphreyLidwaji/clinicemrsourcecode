<?php
/**
 * Central system audit logger (SESSION-aware)
 *
 * @param mysqli $mysqli
 * @param array  $data
 */
function audit_log(mysqli $mysqli, array $data): void
{
    // Required fields
    if (empty($data['action']) || empty($data['module']) || empty($data['table_name'])) {
        return;
    }

    /* =========================
       SESSION-derived identity
       ========================= */
    $user_id   = $data['user_id']
        ?? ($_SESSION['user_id'] ?? null);

    $user_name = $data['user_name']
        ?? ($_SESSION['user_name'] ?? null);

    $user_role = $data['user_role']
        ?? ($_SESSION['user_role_name'] ?? null);

    /* =========================
       Normalize audit fields
       ========================= */
    $action      = strtoupper($data['action']);
    $module      = $data['module'];
    $table_name  = $data['table_name'];
    $entity_type = $data['entity_type'] ?? null;
    $record_id   = $data['record_id']   ?? null;
    $patient_id  = $data['patient_id']  ?? null;
    $visit_id    = $data['visit_id']    ?? null;
    $description = $data['description'] ?? null;
    $status      = $data['status']      ?? 'SUCCESS';

    $old_values = isset($data['old_values'])
        ? json_encode($data['old_values'], JSON_UNESCAPED_UNICODE)
        : null;

    $new_values = isset($data['new_values'])
        ? json_encode($data['new_values'], JSON_UNESCAPED_UNICODE)
        : null;

    $ip_address = $_SERVER['REMOTE_ADDR']     ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    /* =========================
       Insert audit record
       ========================= */
    $sql = "INSERT INTO audit_logs
            (user_id, user_name, user_role, action, module, table_name,
             entity_type, record_id, patient_id, visit_id,
             old_values, new_values, description, status,
             ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return; // never break app flow
    }

    $stmt->bind_param(
        "issssssiiissssss",
        $user_id,
        $user_name,
        $user_role,
        $action,
        $module,
        $table_name,
        $entity_type,
        $record_id,
        $patient_id,
        $visit_id,
        $old_values,
        $new_values,
        $description,
        $status,
        $ip_address,
        $user_agent
    );

    $stmt->execute();
    $stmt->close();
}
