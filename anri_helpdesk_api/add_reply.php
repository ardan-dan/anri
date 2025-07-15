<?php
/**
 * anri_helpdesk_api/add_reply.php
 * Skrip untuk menambahkan balasan dari aplikasi mobile dan mengirim notifikasi email.
 */

// Mulai output buffering untuk menangkap semua kemungkinan output liar
ob_start();

// --- PENGATURAN PENTING ---
// Tentukan path relatif dari file API ini ke folder instalasi utama HESK Anda.
// Contoh: Jika folder API ada di dalam /hesk/api/, maka path-nya adalah '../'
define('IN', 1);
define('HESK_PATH', 'hesk/'); // <-- SESUAIKAN PATH INI

// --- MULAI INTEGRASI DENGAN HESK ---
// Sertakan file pengaturan dan fungsi inti dari HESK
// Ini penting untuk bisa menggunakan fungsi notifikasi email bawaan HESK.
require(HESK_PATH . 'hesk_settings.inc.php');
require(HESK_PATH . 'inc/common.inc.php');
// --- AKHIR INTEGRASI DENGAN HESK ---

// Sertakan file otentikasi dan koneksi kustom Anda
require 'auth_check.php'; // Memastikan staf yang login valid
require 'koneksi.php';    // Koneksi ke database (menggunakan file kustom Anda)

// --- HEADER CORS UNTUK MENGIZINKAN AKSES DARI FLUTTER WEB ---
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Menangani Pre-flight Request (penting untuk browser)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    ob_end_flush();
    exit();
}

// Atur header default sebagai JSON
header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'Terjadi kesalahan yang tidak diketahui.'];

// Nonaktifkan sementara fungsi notifikasi HESK agar tidak berjalan dua kali
$hesk_settings['notify_new_reply'] = 0;

try {
    // Mengambil data dari request POST
    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    $message = isset($_POST['message']) ? hesk_input(trim($_POST['message'])) : '';
    $new_status_text = isset($_POST['new_status']) ? trim($_POST['new_status']) : '';
    $staff_id = isset($_POST['staff_id']) ? intval($_POST['staff_id']) : 1;
    $staff_name = isset($_POST['staff_name']) ? hesk_input(trim($_POST['staff_name'])) : 'Administrator';

    // Validasi input
    if (empty($ticket_id) || empty($message) || empty($new_status_text)) {
        throw new Exception('Data tidak lengkap (ticket_id, message, new_status).');
    }

    $status_map = [
        'New' => 0, 'Waiting Reply' => 1, 'Replied' => 2,
        'Resolved' => 3, 'In Progress' => 4, 'On Hold' => 5,
    ];

    if (!array_key_exists($new_status_text, $status_map)) {
        throw new Exception('Status balasan tidak valid.');
    }

    $new_status_id = $status_map[$new_status_text];

    // Memulai transaction
    mysqli_begin_transaction($conn);

    // 1. Masukkan balasan ke tabel hesk_replies
    $sql_reply = "INSERT INTO `hesk_replies` (`replyto`, `name`, `message`, `message_html`, `dt`, `staffid`) VALUES (?, ?, ?, ?, NOW(), ?)";
    $stmt_reply = mysqli_prepare($conn, $sql_reply);
    mysqli_stmt_bind_param($stmt_reply, 'isssi', $ticket_id, $staff_name, $message, $message, $staff_id);
    if (!mysqli_stmt_execute($stmt_reply)) {
        throw new Exception("Gagal menyimpan balasan: " . mysqli_stmt_error($stmt_reply));
    }
    mysqli_stmt_close($stmt_reply);

    // 2. Update tiket di tabel hesk_tickets
    $sql_ticket = "UPDATE `hesk_tickets` SET 
                    `status` = ?, 
                    `lastchange` = NOW(), 
                    `replies` = `replies` + 1, 
                    `staffreplies` = `staffreplies` + 1,
                    `lastreplier` = '1', 
                    `replierid` = ?
                   WHERE `id` = ?";
    $stmt_ticket = mysqli_prepare($conn, $sql_ticket);
    mysqli_stmt_bind_param($stmt_ticket, 'iii', $new_status_id, $staff_id, $ticket_id);
    if (!mysqli_stmt_execute($stmt_ticket)) {
        throw new Exception("Gagal memperbarui tiket: " . mysqli_stmt_error($stmt_ticket));
    }
    mysqli_stmt_close($stmt_ticket);

    // Jika semua berhasil, commit transaction
    mysqli_commit($conn);

    // --- BLOK BARU: Pemicu Notifikasi Email via HESK ---
    // Ambil detail tiket lengkap yang diperlukan untuk notifikasi email
    $sql_ticket_info = "SELECT * FROM `".hesk_dbEscape($hesk_settings['db_pfix'])."tickets` WHERE `id` = ".intval($ticket_id)." LIMIT 1";
    $res_ticket_info = hesk_dbQuery($sql_ticket_info);

    if (hesk_dbNumRows($res_ticket_info) == 1) {
        $ticket = hesk_dbFetchAssoc($res_ticket_info);
        // Panggil fungsi notifikasi email bawaan HESK
        hesk_notify_customer_new_reply($ticket, $message, $staff_name);
    }
    // --- AKHIR BLOK BARU ---

    $response['success'] = true;
    $response['message'] = 'Balasan berhasil dikirim dan notifikasi email telah diproses.';

} catch (Exception $e) {
    // Jika ada error di mana pun, batalkan semua perubahan
    mysqli_rollback($conn);
    http_response_code(500); // Set kode error server
    $response['success'] = false;
    $response['message'] = 'Gagal memproses balasan: ' . $e->getMessage();
}

// Bersihkan semua output yang mungkin sudah ada
ob_clean();

// Cetak response sebagai JSON murni
echo json_encode($response);

// Tutup koneksi database
mysqli_close($conn);

// Hentikan eksekusi skrip
exit();
?>