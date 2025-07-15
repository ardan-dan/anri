<?php
// Memuat file .env
require_once __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Mengambil kredensial dari .env
$botToken = $_ENV['TELEGRAM_BOT_TOKEN'];
$chatId = $_ENV['TELEGRAM_CHAT_ID'];

header('Content-Type: text/plain');
echo "Mencoba mengirim pesan tes ke Telegram...\n\n";

if (empty($botToken) || empty($chatId)) {
    die("GAGAL: Pastikan TELEGRAM_BOT_TOKEN dan TELEGRAM_CHAT_ID sudah benar di file .env");
}

// Pesan yang akan dikirim
$pesanTes = "✅ Ini adalah pesan tes dari server ANRI Helpdesk. Jika Anda menerima ini, koneksi berhasil!";

// Kirim pesan menggunakan cURL
$url = "https://api.telegram.org/bot{$botToken}/sendMessage";
$params = [
    'chat_id' => $chatId,
    'text' => $pesanTes,
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

// Menampilkan hasil diagnosis di browser
if ($error) {
    echo "HASIL: GAGAL ❌\n";
    echo "Pesan Error cURL: " . $error;
} else {
    echo "HASIL: BERHASIL DIKIRIM ✅\n\n";
    echo "Respons dari Telegram:\n" . $response;
}
?>