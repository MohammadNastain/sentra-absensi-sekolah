<?php

return [
    /*
    | Kunci HMAC kredensial (QR/RFID) berversi, untuk rotasi: versi lama tetap dipakai mencocokkan kartu yang
    | sudah terbit, versi terbaru dipakai menerbitkan kartu baru. Bila CREDENTIAL_HMAC_KEY (base64) tidak diisi,
    | kunci diturunkan dari APP_KEY lewat HKDF. Produksi sebaiknya memakai kunci terpisah.
    */
    'credential_hmac_current_version' => (int) env('CREDENTIAL_HMAC_VERSION', 1),
    'credential_hmac_key' => env('CREDENTIAL_HMAC_KEY'),

    /* Batas percobaan login per menit per akun+IP. Jangan dinaikkan di produksi. */
    'login_attempts_per_minute' => (int) env('LOGIN_ATTEMPTS_PER_MINUTE', 5),

    /*
    | Ambang kesehatan hari (ADR 0005 §5.2), nilai awal yang perlu dikalibrasi saat pilot: terminal aktif yang tidak
    | terlihat lebih dari N menit kumulatif selama jendela masuk, atau tingkat error 5xx scan >= rasio dengan minimal
    | N request, menahan aturan alpa.
    */
    'health_offline_minutes' => (int) env('HEALTH_OFFLINE_MINUTES', 10),
    'health_error_rate' => (float) env('HEALTH_ERROR_RATE', 0.05),
    'health_error_min_requests' => (int) env('HEALTH_ERROR_MIN_REQUESTS', 20),

    /*
    | Rilis modul LMS (ADR 0010 §6). Selama false, fitur lms.* tidak pernah efektif (belum dirilis di registri).
    | Hanya dinyalakan di tes dan lingkungan uji; rilis sungguhan mengganti nilai awal ini di PR terakhir tahap LMS.
    */
    'lms_released' => (bool) env('LMS_RELEASED', false),

    /* Retensi alamat IP pada audit log (hari). D-26: usulan 90 hari, menunggu tinjauan hukum. */
    'audit_ip_retention_days' => (int) env('AUDIT_IP_RETENTION_DAYS', 90),
];
