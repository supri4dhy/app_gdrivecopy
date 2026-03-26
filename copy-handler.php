<?php
/**
 * Nama File: copy-handler.php
 * Lokasi: /apps/gdrive-manager/
 * Fungsi: Menangani proses server-side copy antar folder Drive
 * Waktu Update: 2026-03-26
 */

require_once 'vendor/autoload.php';

// Inisialisasi Google Client
$client = new Google\Client();
$client->setAuthConfig('credentials.json');
$client->addScope(Google\Service\Drive::DRIVE);

$service = new Google\Service\Drive($client);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fileId = $_POST['file_id']; // ID file dari Shared With Me
    $targetFolderId = $_POST['target_folder_id']; // ID folder tujuan di Drive-mu

    try {
        // Objek file baru (bisa ganti nama jika mau)
        $copiedFile = new Google\Service\Drive\DriveFile([
            'parents' => [$targetFolderId]
        ]);

        // Proses Copy (Server-to-Server)
        // Tidak memakan kuota bandwidth internet rumah/kantormu
        $result = $service->files->copy($fileId, $copiedFile, ['fields' => 'id, name']);
        
        echo json_encode(['status' => 'success', 'data' => $result]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}