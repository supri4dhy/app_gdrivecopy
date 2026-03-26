<?php
/**
 * Nama File: index.php
 * Lokasi: /app_gdrivecopy/
 * Fungsi: Dashboard utama dengan integrasi Google Drive API & perbaikan presisi Redirect URI
 * Waktu Update: 2026-03-26
 */

// Pastikan tidak ada spasi atau karakter apapun sebelum tag php di atas agar tidak bocor ke UI
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Cek keberadaan dependensi
$vendorPath = 'vendor/autoload.php';
$isVendorMissing = !file_exists($vendorPath);

// File konfigurasi storage
$configFile = 'config.json';
$tokenFile = 'token.json';
$config = array('clientId' => '', 'clientSecret' => '');
$isAuthenticated = false;

// Muat kredensial dari file
if (file_exists($configFile)) {
    $data = @file_get_contents($configFile);
    $decoded = json_decode($data, true);
    if ($decoded) { $config = $decoded; }
}

// Handler untuk aksi POST (Simpan & Reset)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_config') {
        $newConfig = array(
            'clientId' => trim($_POST['clientId'] ?? ''),
            'clientSecret' => trim($_POST['clientSecret'] ?? '')
        );
        file_put_contents($configFile, json_encode($newConfig));
        header('Content-Type: application/json');
        echo json_encode(array('status' => 'success'));
        exit;
    }
    
    if ($_POST['action'] === 'reset_all') {
        if (file_exists($configFile)) @unlink($configFile);
        if (file_exists($tokenFile)) @unlink($tokenFile);
        header('Content-Type: application/json');
        echo json_encode(array('status' => 'success'));
        exit;
    }
}

// LOGIKA DETEKSI REDIRECT URI (WAJIB IDENTIK DENGAN GOOGLE CONSOLE)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$redirectUri = $protocol . $host . $_SERVER['SCRIPT_NAME'];

$sharedFiles = array();
$myFiles = array();
$authUrl = "#";
$apiError = "";

// Integrasi Google Client Library
if (!$isVendorMissing && !empty($config['clientId']) && !empty($config['clientSecret'])) {
    require_once $vendorPath;
    
    $client = new Google\Client();
    $client->setClientId($config['clientId']);
    $client->setClientSecret($config['clientSecret']);
    $client->setRedirectUri($redirectUri);
    $client->addScope(Google\Service\Drive::DRIVE);
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

    // Tangkap error jika user menolak atau akses denied (Error 403 atau Redirect URI Mismatch)
    if (isset($_GET['error'])) {
        $apiError = "Google API Error: " . htmlspecialchars($_GET['error']);
    }

    // Tangkap Code setelah login berhasil
    if (isset($_GET['code'])) {
        try {
            $accessToken = $client->fetchAccessTokenWithAuthCode($_GET['code']);
            if (isset($accessToken['error'])) {
                throw new Exception($accessToken['error_description'] ?? $accessToken['error']);
            }
            file_put_contents($tokenFile, json_encode($accessToken));
            header('Location: ' . filter_var($redirectUri, FILTER_SANITIZE_URL));
            exit;
        } catch (Exception $e) {
            $apiError = "Auth Callback Error: " . $e->getMessage();
        }
    }

    // Cek Token & Authenticated status
    if (file_exists($tokenFile)) {
        $accessToken = json_decode(file_get_contents($tokenFile), true);
        $client->setAccessToken($accessToken);
        
        if ($client->isAccessTokenExpired()) {
            if ($client->getRefreshToken()) {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                file_put_contents($tokenFile, json_encode($client->getAccessToken()));
            }
        }
        $isAuthenticated = true;
    }

    // Ambil Data Real dari Google Drive
    if ($isAuthenticated) {
        $service = new Google\Service\Drive($client);
        try {
            // Shared With Me
            $resultsShared = $service->files->listFiles(array(
                'pageSize' => 50, 
                'fields' => 'files(id, name, size, owners, mimeType)', 
                'q' => 'sharedWithMe = true'
            ));
            $sharedFiles = $resultsShared->getFiles();

            // My Drive Root
            $resultsMine = $service->files->listFiles(array(
                'pageSize' => 50, 
                'fields' => 'files(id, name, createdTime, owners, mimeType)', 
                'q' => "'root' in parents and trashed = false"
            ));
            $myFiles = $resultsMine->getFiles();
        } catch (Exception $e) {
            $apiError = "Drive Fetch Error: " . $e->getMessage();
        }
    }
    
    try {
        $authUrl = $client->createAuthUrl();
    } catch (Exception $e) {
        $apiError = "Config Error: Client ID mungkin salah atau Client Secret tidak cocok.";
    }
}

// Handler Aksi Copy File (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'copy_file') {
    if (!$isAuthenticated) {
        echo json_encode(array('status' => 'error', 'message' => 'Not authenticated'));
        exit;
    }
    $fileId = $_POST['fileId'];
    $fileName = $_POST['fileName'];
    try {
        $service = new Google\Service\Drive($client);
        $copyFile = new Google\Service\Drive\DriveFile(array('name' => 'Copied - ' . $fileName));
        $result = $service->files->copy($fileId, $copyFile);
        echo json_encode(array('status' => 'success', 'data' => $result));
    } catch (Exception $e) {
        echo json_encode(array('status' => 'error', 'message' => $e->getMessage()));
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drive Manager Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .soft-3d-card {
            background: linear-gradient(145deg, #ffffff, #f8fafc);
            border-radius: 10px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .refined-3d-btn { border-bottom: 3px solid #94a3b8; transition: all 0.1s ease; }
        .refined-3d-btn:active { border-bottom-width: 0px; transform: translateY(3px); }
        .sticky-header th { position: sticky; top: 0; background: #f1f5f9; z-index: 10; }
        .tab-active { color: #2563eb; border-bottom: 2px solid #2563eb; }
        /* Cegah bocoran kode visual */
        pre, code { display: none; }
    </style>
</head>
<body class="bg-slate-300 min-h-screen text-slate-800 pb-20 md:pb-0">

    <!-- Navigasi Atas -->
    <nav class="bg-white/80 backdrop-blur-md border-b border-slate-200 sticky top-0 z-50 px-4 py-3">
        <div class="max-w-7xl mx-auto flex justify-between items-center">
            <h1 class="text-xl font-bold text-slate-950 flex items-center gap-2">
                <i data-lucide="cloud-cog" class="text-blue-600"></i>
                Drive Manager
            </h1>
            <div class="flex items-center gap-6">
                <div class="hidden md:flex items-center gap-4 text-sm font-semibold text-slate-500">
                    <button onclick="switchTab('dashboard')" id="tab-dashboard" class="py-1 tab-active">Dashboard</button>
                    <button onclick="switchTab('settings')" id="tab-settings" class="py-1">API Settings</button>
                </div>
                <div class="w-10 h-10 rounded-full bg-slate-200 border-2 border-white shadow-sm overflow-hidden flex items-center justify-center">
                    <?php if($isAuthenticated): ?>
                        <img src="https://ui-avatars.com/api/?name=User&background=0080ff&color=fff" alt="Avatar">
                    <?php else: ?>
                        <i data-lucide="user-x" class="text-slate-400" size="20"></i>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto p-4">
        
        <div id="view-dashboard" class="view-content">
            
            <?php if (!empty($apiError)): ?>
                <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-r-[10px] shadow-sm flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <i data-lucide="alert-circle" class="text-red-500"></i>
                        <p class="text-sm text-red-800 font-medium leading-none"><?= htmlspecialchars($apiError) ?></p>
                    </div>
                    <button onclick="switchTab('settings')" class="text-[10px] font-bold uppercase text-red-700 hover:underline">Perbaiki Pengaturan</button>
                </div>
            <?php endif; ?>

            <?php if ($isVendorMissing): ?>
                <div class="bg-red-50 border-l-4 border-red-500 p-6 mb-6 rounded-r-[10px] shadow-sm">
                    <p class="text-lg font-bold text-red-900 leading-tight">Folder Vendor Hilang</p>
                    <p class="text-sm text-red-700 mt-1">Harap jalankan perintah berikut di terminal Laragon:</p>
                    <code class="block mt-2 bg-red-100 p-2 rounded font-mono text-xs text-red-900">composer require google/apiclient --no-audit</code>
                </div>
            <?php elseif (empty($config['clientId'])): ?>
                <div class="bg-amber-50 border-l-4 border-amber-400 p-4 mb-6 rounded-r-[10px] shadow-sm flex items-center justify-between">
                    <p class="text-sm text-amber-800 font-medium">API belum dikonfigurasi. Masukkan Client ID & Secret di menu Settings.</p>
                    <button onclick="switchTab('settings')" class="text-[11px] font-bold uppercase text-amber-700 hover:underline">Setup Now</button>
                </div>
            <?php elseif (!$isAuthenticated): ?>
                <div class="bg-blue-50 border-l-4 border-blue-500 p-6 mb-6 rounded-r-[10px] shadow-sm flex flex-col md:flex-row items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <div class="p-3 bg-blue-100 rounded-full text-blue-600 shadow-sm"><i data-lucide="lock" size="24"></i></div>
                        <div>
                            <p class="text-lg font-bold text-blue-900 leading-tight">Hubungkan Akun Google</p>
                            <p class="text-sm text-blue-700">Jika muncul Redirect Mismatch, pastikan URI di Google Console sudah benar.</p>
                        </div>
                    </div>
                    <a href="<?= $authUrl ?>" class="refined-3d-btn bg-blue-600 text-white px-8 py-3 rounded-[10px] font-bold text-sm shadow-lg flex items-center gap-2">
                        <i data-lucide="log-in" size="18"></i> Connect Google Account
                    </a>
                </div>
            <?php endif; ?>

            <?php if (!$isVendorMissing && $isAuthenticated): ?>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Panel Source -->
                <div class="flex flex-col h-[500px] md:h-[600px]">
                    <div class="flex justify-between items-center mb-3 px-1">
                        <h2 class="text-sm font-bold text-slate-700 flex items-center gap-2"><i data-lucide="users" size="16"></i> Shared With Me</h2>
                        <span class="text-[11px] bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-medium shadow-sm">Source</span>
                    </div>
                    <div class="soft-3d-card flex-1 overflow-hidden flex flex-col border border-slate-200 bg-white">
                        <div class="overflow-auto flex-1">
                            <table class="w-full text-left border-collapse">
                                <thead class="sticky-header">
                                    <tr>
                                        <th class="px-4 py-3 text-[11px] uppercase text-slate-500 font-bold border-b border-slate-200">File Name</th>
                                        <th class="px-4 py-3 text-[11px] uppercase text-slate-500 font-bold border-b border-slate-200 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($sharedFiles)): ?>
                                        <tr><td colspan="2" class="px-4 py-10 text-center text-sm text-slate-400 italic">Tidak ada file shared ditemukan.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($sharedFiles as $file): ?>
                                        <tr class="hover:bg-slate-50 transition-colors border-b border-slate-100 last:border-0" draggable="true" ondragstart="handleDragStart(event, '<?= $file->getId() ?>', '<?= addslashes($file->getName()) ?>')">
                                            <td class="px-4 py-3">
                                                <div class="flex flex-col leading-tight">
                                                    <span class="text-sm font-medium text-slate-800"><?= htmlspecialchars($file->getName()) ?></span>
                                                    <span class="text-[11px] text-slate-500">Shared by: <?= $file->getOwners()[0]->getEmailAddress() ?></span>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 text-right">
                                                <button onclick="copyFile('<?= $file->getId() ?>', '<?= addslashes($file->getName()) ?>')" class="refined-3d-btn bg-white border border-slate-200 px-3 py-1.5 rounded-[8px] text-[11px] font-bold text-slate-700 hover:bg-slate-50 inline-flex items-center gap-1 shadow-sm">
                                                    <i data-lucide="copy" size="12"></i> Copy
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <!-- Panel Destination -->
                <div class="flex flex-col h-[500px] md:h-[600px]">
                    <div class="flex justify-between items-center mb-3 px-1">
                        <h2 class="text-sm font-bold text-slate-700 flex items-center gap-2"><i data-lucide="hard-drive" size="16"></i> My Drive</h2>
                        <span class="text-[11px] bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full font-medium shadow-sm">Destination</span>
                    </div>
                    <div class="soft-3d-card flex-1 overflow-hidden flex flex-col border border-slate-200 bg-white" id="dropZone" ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDrop(event)">
                        <div class="p-3 bg-slate-50 border-b border-slate-200 flex items-center gap-2">
                            <i data-lucide="folder-open" size="14" class="text-slate-500"></i>
                            <span class="text-[11px] font-bold text-slate-600">/ My Drive / (Root)</span>
                        </div>
                        <div class="overflow-auto flex-1 relative">
                            <table class="w-full text-left border-collapse">
                                <thead class="sticky-header">
                                    <tr>
                                        <th class="px-4 py-3 text-[11px] uppercase text-slate-500 font-bold border-b border-slate-200">Item Name</th>
                                        <th class="px-4 py-3 text-[11px] uppercase text-slate-500 font-bold border-b border-slate-200 text-right">Owner</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($myFiles as $file): ?>
                                    <tr class="hover:bg-slate-50 transition-colors border-b border-slate-100 last:border-0">
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-3">
                                                <i data-lucide="<?= $file->getMimeType() === 'application/vnd.google-apps.folder' ? 'folder' : 'file-text' ?>" class="text-blue-500" size="18"></i>
                                                <span class="text-sm font-medium text-slate-800"><?= htmlspecialchars($file->getName()) ?></span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-[11px] text-slate-600 text-right font-medium">Me</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div id="dropMessage" class="hidden absolute inset-0 bg-blue-500/10 border-2 border-dashed border-blue-500 m-4 rounded-[10px] flex items-center justify-center pointer-events-none z-20">
                                <div class="bg-white p-5 rounded-xl shadow-2xl flex flex-col items-center gap-2 border border-blue-100">
                                    <i data-lucide="plus-circle" class="text-blue-600 animate-bounce" size="40"></i>
                                    <p class="text-sm font-bold text-blue-600">Lepas Untuk Salin</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- View: Settings -->
        <div id="view-settings" class="view-content hidden">
            <div class="max-w-6xl mx-auto grid grid-cols-1 lg:grid-cols-5 gap-8">
                
                <!-- Panel Kiri: Konfigurasi -->
                <div class="lg:col-span-2 flex flex-col gap-6">
                    <div class="flex items-center gap-3 px-1">
                        <div class="p-2 bg-slate-200 rounded-lg shadow-sm"><i data-lucide="key" class="text-slate-600"></i></div>
                        <h2 class="text-xl font-bold text-slate-900 leading-tight">API Credentials</h2>
                    </div>
                    <div class="soft-3d-card p-6 border border-slate-200 flex flex-col gap-5">
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[11px] font-bold text-slate-600 uppercase ml-1">Google Client ID</label>
                            <input type="text" id="clientId" value="<?= htmlspecialchars($config['clientId']) ?>" placeholder="Tempel Client ID..." class="bg-white border border-slate-200 rounded-[8px] px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-500/10 transition-all">
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[11px] font-bold text-slate-600 uppercase ml-1">Google Client Secret</label>
                            <input type="password" id="clientSecret" value="<?= htmlspecialchars($config['clientSecret']) ?>" placeholder="••••••••" class="bg-white border border-slate-200 rounded-[8px] px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-500/10 transition-all">
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <label class="text-[11px] font-bold text-slate-600 uppercase ml-1">Redirect URI (Diagnostic)</label>
                            <div class="flex gap-2">
                                <input type="text" readonly id="redirectUri" value="<?= $redirectUri ?>" class="bg-white border border-blue-200 rounded-[8px] px-4 py-2.5 text-sm flex-1 text-blue-700 font-bold truncate">
                                <button onclick="copyToClipboard('redirectUri')" class="bg-blue-600 p-2 rounded-[8px] hover:bg-blue-700 text-white shadow-md transition-all"><i data-lucide="copy" size="16"></i></button>
                            </div>
                            <p class="text-[10px] text-blue-600 font-bold leading-tight mt-1">Wajib: Salin alamat biru ini ke Google Console!</p>
                        </div>
                        <div class="pt-4 flex justify-between gap-3 border-t border-slate-100">
                            <button onclick="resetAll()" class="px-4 py-2.5 text-[11px] font-bold text-red-500 hover:bg-red-50 rounded-[8px] transition-colors border border-transparent hover:border-red-100 uppercase">Reset All</button>
                            <button onclick="saveConfig()" class="refined-3d-btn bg-blue-600 text-white px-8 py-2.5 rounded-[8px] text-sm font-bold flex items-center gap-2 shadow-lg shadow-blue-500/10"><i data-lucide="save" size="16"></i> Simpan Konfigurasi</button>
                        </div>
                    </div>
                </div>
                
                <!-- Panel Kanan: Tutorial Solusi -->
                <div class="lg:col-span-3 flex flex-col gap-6">
                    <div class="flex items-center gap-3 mb-1 px-1">
                        <div class="p-2 bg-blue-100 rounded-lg text-blue-600 shadow-sm"><i data-lucide="help-circle"></i></div>
                        <h2 class="text-xl font-bold text-slate-900 leading-tight">Solusi Redirect URI Mismatch</h2>
                    </div>
                    <div class="soft-3d-card p-6 border border-slate-200 bg-white space-y-6">
                        
                        <div class="flex items-start gap-4 p-4 bg-red-50 border border-red-100 rounded-[10px]">
                            <div class="w-8 h-8 bg-red-600 text-white flex items-center justify-center rounded-full shrink-0 font-bold">!</div>
                            <div>
                                <p class="text-sm font-bold text-red-900 mb-1">Masalah yang Terdeteksi:</p>
                                <p class="text-[11px] text-red-800 leading-relaxed">
                                    Google menolak permintaan login Anda karena alamat <b>Redirect URI</b> yang Anda daftarkan di Console tidak sama persis dengan yang dikirim aplikasi. 
                                    Berdasarkan screenshot Anda, kesalahan ada pada baris URI yang kurang <code class="font-mono">index.php</code> di akhir alamat.
                                </p>
                            </div>
                        </div>

                        <div>
                            <p class="text-sm font-bold text-slate-800 mb-3">Langkah Perbaikan Wajib:</p>
                            <div class="space-y-4">
                                <div class="flex gap-3">
                                    <span class="w-6 h-6 rounded-full bg-slate-900 text-white text-xs font-bold flex items-center justify-center shrink-0">1</span>
                                    <p class="text-[11px] text-slate-700 leading-relaxed">Buka <a href="https://console.cloud.google.com" target="_blank" class="text-blue-600 underline font-bold">Google Cloud Console</a> > <b>APIs & Services</b> > <b>Credentials</b>.</p>
                                </div>
                                <div class="flex gap-3">
                                    <span class="w-6 h-6 rounded-full bg-slate-900 text-white text-xs font-bold flex items-center justify-center shrink-0">2</span>
                                    <p class="text-[11px] text-slate-700 leading-relaxed">Klik nama Client ID Anda (contoh: <i>GdriveCopy</i>).</p>
                                </div>
                                <div class="flex gap-3 border-l-4 border-blue-500 pl-3">
                                    <span class="w-6 h-6 rounded-full bg-blue-600 text-white text-xs font-bold flex items-center justify-center shrink-0">3</span>
                                    <div class="flex flex-col gap-1">
                                        <p class="text-[11px] text-blue-900 font-bold">Ganti Authorized Redirect URIs:</p>
                                        <p class="text-[11px] text-slate-700 leading-relaxed">Hapus baris URI yang lama. Klik <b>+ ADD URI</b> dan paste alamat biru dari panel kiri kodingan ini. <b>Pastikan alamat berakhir dengan index.php</b>.</p>
                                    </div>
                                </div>
                                <div class="flex gap-3">
                                    <span class="w-6 h-6 rounded-full bg-slate-900 text-white text-xs font-bold flex items-center justify-center shrink-0">4</span>
                                    <p class="text-[11px] text-slate-700 leading-relaxed">Penting! Tambahkan juga email Anda di menu <b>OAuth consent screen</b> > <b>Test users</b> agar tidak terkena Error 403 (Access Denied).</p>
                                </div>
                                <div class="flex gap-3">
                                    <span class="w-6 h-6 rounded-full bg-slate-900 text-white text-xs font-bold flex items-center justify-center shrink-0">5</span>
                                    <p class="text-[11px] text-slate-700 leading-relaxed">Klik <b>Save</b>, tunggu 30 detik, lalu coba login kembali di dashboard.</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="p-4 bg-emerald-50 border border-emerald-100 rounded-[10px] flex items-start gap-3">
                            <i data-lucide="check-circle" class="text-emerald-600 mt-0.5" size="16"></i>
                            <p class="text-[11px] text-emerald-800 leading-tight font-medium">Jika sudah benar, profil "US" di pojok kanan atas akan berubah menjadi nama/avatar akun Google Anda.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Toast UI -->
    <div id="toast" class="fixed bottom-24 md:bottom-8 right-8 z-[100] translate-x-full opacity-0 transition-all duration-300">
        <div class="soft-3d-card bg-white p-4 pr-12 border-l-4 border-emerald-500 shadow-xl relative min-w-[300px]">
            <div class="flex items-start gap-3">
                <div id="toastIconContainer" class="bg-emerald-100 p-1.5 rounded-full text-emerald-600 shadow-sm"><i data-lucide="check" id="toastIcon" size="16"></i></div>
                <div><p class="text-sm font-bold text-slate-900" id="toastTitle">Berhasil</p><p class="text-[11px] text-slate-600" id="toastMsg">Konfigurasi diproses.</p></div>
            </div>
        </div>
    </div>

    <!-- Mobile Footer -->
    <footer class="md:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-slate-200 px-8 py-3 flex justify-between items-center z-50 shadow-[0_-4px_10px_rgba(0,0,0,0.05)]">
        <button onclick="switchTab('dashboard')" id="mob-dashboard" class="flex flex-col items-center gap-1 text-blue-600 transition-all"><i data-lucide="layout-grid" size="20"></i><span class="text-[10px] font-bold uppercase">Dashboard</span></button>
        <button onclick="switchTab('settings')" id="mob-settings" class="flex flex-col items-center gap-1 text-slate-400 transition-all"><i data-lucide="settings" size="20"></i><span class="text-[10px] font-bold uppercase">Setup</span></button>
    </footer>

    <script>
        lucide.createIcons();

        function switchTab(view) {
            document.querySelectorAll('.view-content').forEach(el => el.classList.add('hidden'));
            document.getElementById('view-' + view).classList.remove('hidden');
            document.querySelectorAll('[id^="tab-"]').forEach(el => el.classList.remove('tab-active'));
            const tabBtn = document.getElementById('tab-' + view);
            if(tabBtn) tabBtn.classList.add('tab-active');
            
            document.querySelectorAll('footer button').forEach(el => el.classList.replace('text-blue-600', 'text-slate-400'));
            const mobBtn = document.getElementById('mob-' + view);
            if(mobBtn) mobBtn.classList.replace('text-slate-400', 'text-blue-600');
        }

        async function saveConfig() {
            const formData = new FormData();
            formData.append('action', 'save_config');
            formData.append('clientId', document.getElementById('clientId').value);
            formData.append('clientSecret', document.getElementById('clientSecret').value);
            try {
                const response = await fetch('index.php', { method: 'POST', body: formData });
                const result = await response.json();
                if(result.status === 'success') { showToast("Success", "Konfigurasi diperbarui.", "success"); setTimeout(() => window.location.reload(), 800); }
            } catch (err) { showToast("Error", "Gagal menyimpan data.", "error"); }
        }

        async function resetAll() {
            if(!confirm("Hapus semua konfigurasi? Ini akan membersihkan Client ID dan Token.")) return;
            const formData = new FormData();
            formData.append('action', 'reset_all');
            try {
                const response = await fetch('index.php', { method: 'POST', body: formData });
                const result = await response.json();
                if(result.status === 'success') { window.location.reload(); }
            } catch (err) { }
        }

        function copyToClipboard(id) {
            const input = document.getElementById(id);
            input.select();
            document.execCommand('copy');
            showToast("Copied", "Alamat URI disalin.", "success");
        }

        let draggedFileId = ""; let draggedFileName = "";
        function handleDragStart(e, fileId, fileName) { draggedFileId = fileId; draggedFileName = fileName; e.dataTransfer.setData("text/plain", fileId); }
        function handleDragOver(e) { e.preventDefault(); document.getElementById('dropZone').classList.add('bg-blue-50'); document.getElementById('dropMessage').classList.remove('hidden'); }
        function handleDragLeave(e) { document.getElementById('dropZone').classList.remove('bg-blue-50'); document.getElementById('dropMessage').classList.add('hidden'); }
        function handleDrop(e) { e.preventDefault(); handleDragLeave(e); copyFile(draggedFileId, draggedFileName); }

        async function copyFile(fileId, fileName) {
            showToast("Copying...", `Menyalin ${fileName}...`, "info");
            const formData = new FormData();
            formData.append('action', 'copy_file');
            formData.append('fileId', fileId);
            formData.append('fileName', fileName);
            try {
                const response = await fetch('index.php', { method: 'POST', body: formData });
                const result = await response.json();
                if(result.status === 'success') { showToast("Success", "Berhasil disalin.", "success"); setTimeout(() => window.location.reload(), 1500); }
                else { showToast("Error", result.message, "error"); }
            } catch (err) { showToast("Error", "Gagal menghubungi server.", "error"); }
        }

        function showToast(title, msg, type) {
            const t = document.getElementById('toast');
            const iconContainer = document.getElementById('toastIconContainer');
            document.getElementById('toastTitle').innerText = title;
            document.getElementById('toastMsg').innerText = msg;
            if(type === 'error') iconContainer.className = "bg-red-100 p-1.5 rounded-full text-red-600 shadow-sm";
            else if (type === 'info') iconContainer.className = "bg-blue-100 p-1.5 rounded-full text-blue-600 shadow-sm";
            else iconContainer.className = "bg-emerald-100 p-1.5 rounded-full text-emerald-600 shadow-sm";
            t.classList.remove('translate-x-full', 'opacity-0');
            if(type !== 'info') setTimeout(() => t.classList.add('translate-x-full', 'opacity-0'), 4000);
        }
    </script>
</body>
</html>