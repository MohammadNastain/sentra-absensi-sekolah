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
];
